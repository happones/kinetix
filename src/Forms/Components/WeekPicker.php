<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Support\KinetixLocale;

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
