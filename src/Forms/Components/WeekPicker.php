<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

/**
 * A week-only picker. Renders a shadcn calendar that selects the clicked day's
 * ISO week by default (value 'o-\WW', e.g. "2026-W25"), or a native
 * <input type="week"> via native(). Bounds via minValue()/maxValue() ('o-\WW').
 */
class WeekPicker extends Field
{
    protected bool $useCalendar = true;

    protected ?string $dateLocale = null;

    protected ?int $weekStartsOn = null;

    protected function getType(): string
    {
        return 'week-picker';
    }

    /**
     * First day of the week shown in the calendar: 0=Sunday … 6=Saturday
     * (default is the ISO Monday).
     */
    public function startWeek(int $day): static
    {
        $this->weekStartsOn = max(0, min(6, $day));

        return $this;
    }

    /**
     * @return array{numberOfMonths: int, weekdayFormat: ?string, fixedWeeks: bool, weekStartsOn: ?int}
     */
    protected function rangeConfig(): array
    {
        return [
            'numberOfMonths' => 1,
            'weekdayFormat'  => null,
            'fixedWeeks'     => false,
            'weekStartsOn'   => $this->weekStartsOn,
        ];
    }

    /**
     * Opt out of the shadcn calendar and render a native <input type="week">.
     */
    public function native(bool $condition = true): static
    {
        $this->useCalendar = ! $condition;

        return $this;
    }

    /**
     * BCP-47 locale for the calendar (e.g. 'es', 'fr').
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
            'locale'      => $this->dateLocale,
            'minuteStep'  => 5,
            'hour12'      => false,
        ];
    }
}
