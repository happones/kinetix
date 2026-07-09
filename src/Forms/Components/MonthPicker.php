<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Support\KinetixLocale;

/**
 * A month-only picker. Renders a shadcn month grid by default (value 'Y-m',
 * e.g. "2026-06"), or a native <input type="month"> via native(). Bounds via
 * minValue()/maxValue() ('Y-m').
 */
class MonthPicker extends Field
{
    protected bool $useCalendar = true;

    protected ?string $dateLocale = null;

    protected function getType(): string
    {
        return 'month-picker';
    }

    /**
     * Opt out of the shadcn grid and render a native <input type="month">.
     */
    public function native(bool $condition = true): static
    {
        $this->useCalendar = ! $condition;

        return $this;
    }

    /**
     * BCP-47 locale for the month labels (e.g. 'es', 'fr').
     */
    public function locale(string $locale): static
    {
        $this->dateLocale = $locale;

        return $this;
    }

    /**
     * @return array{useCalendar: bool, locale: ?string, minuteStep: int, hour12: bool}
     */
    protected function dateConfig(): array
    {
        return [
            'useCalendar' => $this->useCalendar,
            'locale'      => $this->dateLocale ?? KinetixLocale::bcp47(),
            'minuteStep'  => 5,
            'hour12'      => false,
        ];
    }
}
