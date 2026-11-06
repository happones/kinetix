<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Happones\Kinetix\Forms\Form;
use Illuminate\Database\Eloquent\Model;

/**
 * A list of items, each rendered with the same sub-schema.
 *
 * Disabled sub-fields are enforced on the server for the rows the form
 * renders: when the sub-schema has disabled fields (`disabled()` or
 * `disabledWhen()`, not {@see Field::savedWhenDisabled()}), each stored row
 * travels with a server-issued key (`__kinetix_row`, an HMAC over the form,
 * the field, the record, the row's position and its locked values). On
 * submit, a row carrying a valid key takes its locked values from the stored
 * row it names; a row without one (added in the browser), with a stale one
 * or with one another row already claimed is a NEW row, and its locked
 * sub-fields get their default. One level only: a repeater nested inside a
 * row is enforced in the browser.
 *
 * A live hook that rewrites the rows (`$set('items', …)`) must carry each
 * row's {@see ROW_KEY} across: rows are matched by that key, never by
 * position, and a row rebuilt without it is a new row.
 */
class Repeater extends Field
{
    /** The item key a stored row carries while the form is open. */
    public const ROW_KEY = '__kinetix_row';

    /**
     * The repeated sub-schema rendered for each item.
     *
     * @var array<int, Component>
     */
    protected array $schema = [];

    protected ?int $minItems = null;

    protected ?int $maxItems = null;

    protected ?string $addActionLabel = null;

    protected function getType(): string
    {
        return 'repeater';
    }

    /**
     * Set the sub-schema repeated for every item.
     *
     * @param array<int, Component> $components
     */
    public function schema(array $components): static
    {
        $this->schema = $components;

        return $this;
    }

    public function minItems(int $count): static
    {
        $this->minItems = $count;

        return $this;
    }

    public function maxItems(int $count): static
    {
        $this->maxItems = $count;

        return $this;
    }

    public function addActionLabel(string $label): static
    {
        $this->addActionLabel = $label;

        return $this;
    }

    /**
     * Each item drops the values of the sub-schema fields its own conditions
     * hide — evaluated against THAT item's values, as the browser does when it
     * renders the item. They used to be stored with the item even though the
     * user never saw the field. Keys no field declares are kept as they were.
     */
    public function dehydrate(mixed $value, ?Model $record = null): mixed
    {
        if (is_array($value)) {
            $fields = Form::fieldsIn($this->schema);

            $value = array_map(static function (mixed $item) use ($fields): mixed {
                if (! is_array($item)) {
                    return $item;
                }

                unset($item[self::ROW_KEY]);

                foreach ($fields as $name => $field) {
                    if (array_key_exists($name, $item) && $field->isConditionallyExcluded($item)) {
                        unset($item[$name]);
                    }
                }

                return $item;
            }, $value);
        }

        return parent::dehydrate($value, $record);
    }

    /**
     * The sub-fields whose submitted value the server doesn't trust: disabled
     * for the record or by a `disabledWhen()` condition, and not saved when
     * disabled. In sub-schema order.
     *
     * @return array<string, Field>
     */
    public function lockedFields(?Model $record = null): array
    {
        return array_filter(
            Form::fieldsIn($this->schema),
            static fn (Field $field): bool => ! $field->isSavedWhenDisabled()
                && ($field->isDisabledForRecord($record) || isset($field->conditions['disabled'])),
        );
    }

    /**
     * `$rows` with each row keyed for the browser ({@see ROW_KEY}), when the
     * sub-schema has locked fields; unchanged otherwise. Only for rows built
     * from the stored record (the form's render): a row a hook produces keeps
     * the key it came in with, and gets none when it had none.
     *
     * @param  array<array-key, mixed> $rows
     * @param  class-string<Form>      $formClass
     * @return array<array-key, mixed>
     */
    public function keyRows(array $rows, ?Model $record, string $formClass): array
    {
        $locked = $this->lockedFields($record);

        if ($locked === []) {
            return $rows;
        }

        $index = 0;

        foreach ($rows as $position => $row) {
            if (is_array($row)) {
                $row[self::ROW_KEY] = $this->rowKey($formClass, $record, $index, $row, $locked);
                $rows[$position]    = $row;
            }

            $index++;
        }

        return $rows;
    }

    /**
     * The submitted rows with their locked sub-fields restored: from the
     * stored row a valid, unclaimed key names, else the field's default. The
     * key itself is always removed.
     *
     * @param  array<array-key, mixed> $submitted
     * @param  array<array-key, mixed> $stored    the rows the form rendered (and keyed)
     * @param  class-string<Form>      $formClass
     * @return array<array-key, mixed>
     */
    public function lockRows(array $submitted, array $stored, ?Model $record, string $formClass): array
    {
        $locked = $this->lockedFields($record);
        $stored = array_values($stored);
        $taken  = [];

        foreach ($submitted as $position => $item) {
            if (! is_array($item)) {
                continue;
            }

            $key = $item[self::ROW_KEY] ?? null;
            unset($item[self::ROW_KEY]);

            if ($locked === []) {
                $submitted[$position] = $item;

                continue;
            }

            $source = $this->claim($key, $stored, $taken, $record, $formClass, $locked);

            // A field disabled outright first; then the ones a condition
            // disables, judged on the row with those values restored.
            foreach ([true, false] as $outright) {
                foreach ($locked as $name => $field) {
                    if ($field->isDisabledForRecord($record) !== $outright || ! $field->isDisabledFor($item, $record)) {
                        continue;
                    }

                    if ($source === null) {
                        $item[$name] = $field->hydrate($field->getDefaultValue($record), $record);
                    } elseif (array_key_exists($name, $source)) {
                        $item[$name] = $source[$name];
                    } else {
                        unset($item[$name]);
                    }
                }
            }

            $submitted[$position] = $item;
        }

        return $submitted;
    }

    /**
     * The stored row a submitted key names, when the key is valid for it and
     * no earlier row claimed it; null for a new row.
     *
     * @param  array<int, mixed>         $stored
     * @param  array<int, true>          $taken
     * @param  class-string<Form>        $formClass
     * @param  array<string, Field>      $locked
     * @return array<string, mixed>|null
     */
    private function claim(mixed $key, array $stored, array &$taken, ?Model $record, string $formClass, array $locked): ?array
    {
        if (! is_string($key) || preg_match('/^(\d{1,9})\.[A-Za-z0-9_-]{22}$/', $key, $matches) !== 1) {
            return null;
        }

        $index = (int) $matches[1];
        $row   = $stored[$index] ?? null;

        if (! is_array($row) || isset($taken[$index]) || ! hash_equals($this->rowKey($formClass, $record, $index, $row, $locked), $key)) {
            return null;
        }

        $taken[$index] = true;

        return $row;
    }

    /**
     * `{index}.{mac}`: the row's position and an HMAC (app key) over the
     * form class, the field, the record, the position and the row's locked
     * values in sub-schema order.
     *
     * @param array<array-key, mixed> $row
     * @param array<string, Field>    $locked
     * @param class-string<Form>      $formClass
     */
    private function rowKey(string $formClass, ?Model $record, int $index, array $row, array $locked): string
    {
        $values = [];

        foreach (array_keys($locked) as $name) {
            $values[] = $row[$name] ?? null;
        }

        $message = implode('|', [
            'kinetix-repeater-row',
            $formClass,
            $this->getName(),
            $record?->exists ? $record::class.'#'.$record->getKey() : '-',
            (string) $index,
            (string) json_encode($values, JSON_PRESERVE_ZERO_FRACTION|JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR),
        ]);

        $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $message, self::appKey(), true)), '+/', '-_'), '=');

        return $index.'.'.substr($mac, 0, 22);
    }

    /** The raw app key, a `base64:` one decoded. */
    private static function appKey(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return $decoded === false ? $key : $decoded;
        }

        return $key;
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        $data = parent::toData($operation, $record);
        if ($data === null) {
            return null;
        }

        $childData = [];
        foreach ($this->schema as $component) {
            $componentData = $component->toData($operation, $record);
            if ($componentData !== null) {
                $childData[] = $componentData;
            }
        }

        $data->schema         = $childData;
        $data->minItems       = $this->minItems;
        $data->maxItems       = $this->maxItems;
        $data->addActionLabel = $this->addActionLabel;

        return $data;
    }
}
