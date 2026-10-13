<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

class KeyValue extends Field
{
    /**
     * Fix the keys: one row per key, labelled, with no adding or removing
     * rows. Adds Laravel's `array:title,color` rule, so the server refuses
     * any other key.
     *
     * @param list<string> $keys
     */
    public function keys(array $keys): static
    {
        return $this->rules(['array:'.implode(',', $keys)]);
    }

    protected function getType(): string
    {
        return 'key-value';
    }
}
