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

    protected bool $confirm = false;

    protected bool $showToday = false;

    protected bool $closeOnSelect = true;

    protected ?string $pickerTimezone = null;

    /**
     * Commit only via an explicit Apply button: clicks update a DRAFT inside
     * the popover; dismissing without applying discards it.
     */
    public function confirm(bool $condition = true): static
    {
        $this->confirm = $condition;

        return $this;
    }

    /**
     * Show a current-period shortcut in the popover footer.
     */
    public function todayButton(bool $condition = true): static
    {
        $this->showToday = $condition;

        return $this;
    }

    /**
     * Whether picking a value closes the popover (default true).
     */
    public function closeOnSelect(bool $condition = true): static
    {
        $this->closeOnSelect = $condition;

        return $this;
    }

    /**
     * IANA timezone the current-period preset (and the initial view) reads
     * the clock in. Defaults to Laravel's `app.timezone` — the implicit
     * timezone of every naive value this field stores.
     */
    public function timezone(string $timezone): static
    {
        $this->pickerTimezone = $timezone;

        return $this;
    }

    /**
     * @return array{useCalendar: bool, locale: ?string, minuteStep: int, hour12: bool, confirm: bool, showToday: bool, closeOnSelect: bool, timezone: string}
     */
    protected function dateConfig(): array
    {
        return [
            'useCalendar'   => $this->useCalendar,
            'locale'        => $this->dateLocale ?? KinetixLocale::bcp47(),
            'minuteStep'    => 5,
            'hour12'        => false,
            'confirm'       => $this->confirm,
            'showToday'     => $this->showToday,
            'closeOnSelect' => $this->closeOnSelect,
            'timezone'      => $this->pickerTimezone ?? (string) config('app.timezone', 'UTC'),
        ];
    }
}
