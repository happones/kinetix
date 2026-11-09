<?php

declare(strict_types=1);

namespace Happones\Kinetix\Resources;

use BackedEnum;
use Closure;
use Happones\Kinetix\Data\RecordReferenceData;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Database\Eloquent\Model;
use Stringable;

/**
 * A discreet, copyable identifier for a record — a ULID, UUID or slug shown in
 * part ("Order · …7KQZ") on its pages, and copied whole so support can find the
 * exact record. Useful when the number customers see is per-account (an
 * invoice `code`) and only the global identifier is unambiguous.
 *
 *     RecordReference::make('ulid')              // last 4 characters
 *         ->last(6)                              // or ->first(8) / ->full()
 *         ->label('Order')                       // defaults to the resource's model label
 *         ->copyWithLabel(false);                // copy the bare value
 *
 * The copied text reaches the browser (with the defaults, the full value),
 * so only reference an identifier the viewer may know: never a secret or an
 * internal table name.
 *
 * Unrelated to {@see \Happones\Kinetix\Support\RecordReference}, the
 * internal sealed token comments and tags use.
 */
final class RecordReference
{
    private int $characters = 4;

    /** @var 'start'|'end'|'full' */
    private string $position = 'end';

    private ?string $label = null;

    private bool $hasLabel = true;

    private bool $copyWithLabel = true;

    /** @var (Closure(string, ?string): string)|null */
    private ?Closure $copyUsing = null;

    private function __construct(private ?string $attribute) {}

    /**
     * @param string|null $attribute The record attribute (`ulid`, `uuid`, `slug`…);
     *                               null uses the record's route key.
     */
    public static function make(?string $attribute = null): self
    {
        return new self($attribute);
    }

    /**
     * Show the last characters of the value (the default, 4).
     */
    public function last(int $characters = 4): self
    {
        $this->characters = max(1, $characters);
        $this->position   = 'end';

        return $this;
    }

    /**
     * Show the first characters of the value.
     */
    public function first(int $characters = 4): self
    {
        $this->characters = max(1, $characters);
        $this->position   = 'start';

        return $this;
    }

    /**
     * Show the whole value (a short slug or code).
     */
    public function full(): self
    {
        $this->position = 'full';

        return $this;
    }

    /**
     * The kind of record the reference names ("Order"), translated when a key
     * exists. Defaults to the resource's model label; `false` shows and copies
     * no label at all.
     */
    public function label(string|false $label): self
    {
        $this->hasLabel = $label !== false;
        $this->label    = $label === false ? null : $label;

        return $this;
    }

    /**
     * Copy "Order: 01JD…" (the default) or just the value.
     */
    public function copyWithLabel(bool $condition = true): self
    {
        $this->copyWithLabel = $condition;

        return $this;
    }

    /**
     * Build the copied text yourself, e.g. `fn ($value, $label) => "ORD-{$value}"`.
     *
     * @param Closure(string $value, ?string $label): string $callback
     */
    public function copyUsing(Closure $callback): self
    {
        $this->copyUsing = $callback;

        return $this;
    }

    /**
     * The serialized reference for one record, or null when the record has no
     * value for the attribute.
     */
    public function resolve(Model $record, ?string $defaultLabel = null): ?RecordReferenceData
    {
        $value = $this->stringValue(
            $this->attribute === null ? $record->getRouteKey() : $record->getAttribute($this->attribute),
        );

        if ($value === null) {
            return null;
        }

        $label = $this->hasLabel
            ? ($this->label !== null ? (string) __($this->label) : $defaultLabel)
            : null;
        $label = $label === '' ? null : $label;

        [$visible, $position] = $this->visiblePart($value);

        return new RecordReferenceData(
            visible: $visible,
            position: $position,
            label: $label,
            copy: $this->copyText($value, $label),
        );
    }

    /**
     * A scalar, a backed enum or a plain Stringable (a value object) as a
     * trimmed string. Relations, collections and other structured values are
     * no identifier, so they show nothing rather than their JSON.
     */
    private function stringValue(mixed $raw): ?string
    {
        if ($raw instanceof BackedEnum) {
            $raw = $raw->value;
        }

        $isText = is_scalar($raw)
            || ($raw instanceof Stringable && ! $raw instanceof Arrayable && ! $raw instanceof Jsonable);

        if (! $isText || is_bool($raw)) {
            return null;
        }

        $value = trim((string) $raw);

        return $value === '' ? null : $value;
    }

    /**
     * @return array{0: string, 1: 'start'|'end'|'full'}
     */
    private function visiblePart(string $value): array
    {
        if ($this->position === 'full' || mb_strlen($value) <= $this->characters) {
            return [$value, 'full'];
        }

        return $this->position === 'start'
            ? [mb_substr($value, 0, $this->characters), 'start']
            : [mb_substr($value, -$this->characters), 'end'];
    }

    private function copyText(string $value, ?string $label): string
    {
        if ($this->copyUsing !== null) {
            return (string) ($this->copyUsing)($value, $label);
        }

        return $this->copyWithLabel && $label !== null ? "{$label}: {$value}" : $value;
    }
}
