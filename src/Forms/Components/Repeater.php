<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Happones\Kinetix\Forms\Form;
use Illuminate\Database\Eloquent\Model;

class Repeater extends Field
{
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
