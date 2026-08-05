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

    // Defaults to a 12-hour clock with AM/PM; call twentyFourHour() to opt out.
    protected bool $hour12 = true;

    protected function getType(): string
    {
        return 'time-picker';
    }

    /**
     * Use a 12-hour clock with an AM/PM column (the default).
     */
    public function twelveHour(bool $condition = true): static
    {
        $this->hour12 = $condition;

        return $this;
    }

    /**
     * Use a 24-hour clock (no AM/PM column).
     */
    public function twentyFourHour(bool $condition = true): static
    {
        $this->hour12 = ! $condition;

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

    protected bool $confirm = false;

    protected ?string $pickerTimezone = null;

    /**
     * IANA timezone the Now preset reads the clock in. Defaults to Laravel's
     * `app.timezone` — the implicit timezone of every naive value this field
     * stores.
     */
    public function timezone(string $timezone): static
    {
        $this->pickerTimezone = $timezone;

        return $this;
    }

    /**
     * Commit only via an explicit Apply button: column clicks update a DRAFT
     * inside the popover; dismissing without applying discards it.
     */
    public function confirm(bool $condition = true): static
    {
        $this->confirm = $condition;

        return $this;
    }

    /**
     * @return array{useCalendar: bool, locale: ?string, minuteStep: int, hour12: bool, confirm: bool, timezone: string}
     */
    protected function dateConfig(): array
    {
        return [
            'useCalendar' => $this->useCalendar,
            'locale'      => null,
            'minuteStep'  => $this->minuteStep,
            'hour12'      => $this->hour12,
            'confirm'     => $this->confirm,
            'timezone'    => $this->pickerTimezone ?? (string) config('app.timezone', 'UTC'),
        ];
    }
}
