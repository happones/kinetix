<?php

declare(strict_types=1);

namespace Happones\Kinetix\Infolists\Components;

use Illuminate\Database\Eloquent\Model;

/**
 * Displays an array / JSON attribute as a list of key → value pairs (the
 * read-only infolist counterpart of the KeyValue form field). Optional
 * `keyLabel` / `valueLabel` head the two columns.
 *
 *     KeyValueEntry::make('meta')->keyLabel('Property')->valueLabel('Value');
 */
class KeyValueEntry extends Entry
{
    protected ?string $keyLabel = null;

    protected ?string $valueLabel = null;

    protected function getType(): string
    {
        return 'key-value';
    }

    public function keyLabel(string $label): static
    {
        $this->keyLabel = $label;

        return $this;
    }

    public function valueLabel(string $label): static
    {
        $this->valueLabel = $label;

        return $this;
    }

    /**
     * Normalise the state to a flat `{ key: scalarValue }` map so the frontend
     * renders predictable rows (nested values are JSON-encoded).
     */
    public function getState(?Model $record = null): mixed
    {
        $value = parent::getState($record);

        if (! is_array($value)) {
            return null;
        }

        $pairs = [];

        foreach ($value as $key => $item) {
            $pairs[(string) $key] = is_scalar($item) || $item instanceof \Stringable
                ? (string) $item
                : (string) json_encode($item);
        }

        return $pairs;
    }

    protected function getExtraData(?Model $record = null): array
    {
        return [
            'keyLabel'   => $this->keyLabel,
            'valueLabel' => $this->valueLabel,
        ];
    }
}
