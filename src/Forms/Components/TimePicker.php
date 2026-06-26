<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

/**
 * A time-only field (no date). Renders the shadcn scrollable hour/minute
 * (+ AM/PM) columns by default, or a native <input type="time"> via native().
 * Stores an 'H:i' string (e.g. "14:30").
 */
class TimePicker extends Field
{
    protected bool $useCalendar = true;

    protected int $minuteStep = 5;

    protected bool $hour12 = false;

    protected function getType(): string
    {
        return 'time-picker';
    }

    /**
     * Use a 12-hour clock with an AM/PM column instead of 24-hour.
     */
    public function twelveHour(bool $condition = true): static
    {
        $this->hour12 = $condition;

        return $this;
    }

    /**
     * Opt out of the shadcn time picker and render a native <input type="time">.
     */
    public function native(bool $condition = true): static
    {
        $this->useCalendar = ! $condition;

        return $this;
    }

    /**
     * Minute granularity for the time column (default 5).
     */
    public function minuteStep(int $step): static
    {
        $this->minuteStep = max(1, $step);

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
            'minuteStep'  => $this->minuteStep,
            'hour12'      => $this->hour12,
        ];
    }
}
