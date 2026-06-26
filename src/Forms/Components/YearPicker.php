<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

/**
 * A year-only picker. Renders a shadcn year grid by default (value 'Y', e.g.
 * "2026"), or a native <input type="number"> via native(). Bounds via
 * minValue()/maxValue() ('Y').
 */
class YearPicker extends Field
{
    protected bool $useCalendar = true;

    protected function getType(): string
    {
        return 'year-picker';
    }

    /**
     * Opt out of the shadcn grid and render a native number input.
     */
    public function native(bool $condition = true): static
    {
        $this->useCalendar = ! $condition;

        return $this;
    }

    /**
     * @return array{useCalendar: bool, locale: ?string, minuteStep: int, hour12: bool}
     */
    protected function dateConfig(): array
    {
        return [
            'useCalendar' => $this->useCalendar,
            'locale'      => null,
            'minuteStep'  => 5,
            'hour12'      => false,
        ];
    }
}
