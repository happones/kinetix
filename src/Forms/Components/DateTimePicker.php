<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Support\KinetixLocale;

class DateTimePicker extends Field
{
    protected bool $useCalendar = true;

    protected ?string $dateLocale = null;

    protected int $minuteStep = 5;

    protected bool $hour12 = false;

    protected function getType(): string
    {
        return 'datetime-picker';
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
     * Use a 12-hour clock with an AM/PM column instead of 24-hour.
     */
    public function twelveHour(bool $condition = true): static
    {
        $this->hour12 = $condition;

        return $this;
    }

    /**
     * Commit only via an explicit Apply button: calendar/time clicks update a
     * DRAFT inside the popover; dismissing without applying discards it.
     */
    public function confirm(bool $condition = true): static
    {
        $this->confirm = $condition;

        return $this;
    }

    /**
     * Opt out of the shadcn picker and render a plain native datetime input.
     */
    public function native(bool $condition = true): static
    {
        $this->useCalendar = ! $condition;

        return $this;
    }

    /**
     * BCP-47 locale for the calendar (e.g. 'es', 'fr', 'en-US').
     */
    public function locale(string $locale): static
    {
        $this->dateLocale = $locale;

        return $this;
    }

    /**
     * Minute granularity for the time selects (default 5).
     */
    public function minuteStep(int $step): static
    {
        $this->minuteStep = max(1, $step);

        return $this;
    }

    /**
     * @return array{useCalendar: bool, locale: ?string, minuteStep: int, hour12: bool, confirm: bool, timezone: string}
     */
    protected function dateConfig(): array
    {
        return [
            'useCalendar' => $this->useCalendar,
            'locale'      => $this->dateLocale ?? KinetixLocale::bcp47(),
            'minuteStep'  => $this->minuteStep,
            'hour12'      => $this->hour12,
            'confirm'     => $this->confirm,
            'timezone'    => $this->pickerTimezone ?? (string) config('app.timezone', 'UTC'),
        ];
    }
}
