<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Support\KinetixLocale;

/**
 * A date-range field storing `{from, to}` (each an ISO 'Y-m-d' string). Renders
 * the shadcn range calendar in a popover by default, or two native date inputs
 * via native(). Bounds via minValue()/maxValue().
 */
class DateRangePicker extends Field
{
    protected bool $useCalendar = true;

    protected ?string $dateLocale = null;

    protected int $numberOfMonths = 1;

    protected ?string $weekdayFormat = null;

    protected bool $fixedWeeks = false;

    protected bool $confirm = false;

    protected bool $showToday = false;

    protected bool $closeOnSelect = true;

    protected ?string $pickerTimezone = null;

    protected function getType(): string
    {
        return 'date-range-picker';
    }

    /**
     * Commit only via an explicit Apply button: calendar clicks update a
     * DRAFT inside the popover; dismissing without applying discards it.
     */
    public function confirm(bool $condition = true): static
    {
        $this->confirm = $condition;

        return $this;
    }

    /**
     * Show a "Today" shortcut (from = to = today) in the popover footer.
     */
    public function todayButton(bool $condition = true): static
    {
        $this->showToday = $condition;

        return $this;
    }

    /**
     * Whether completing the range closes the popover (default true).
     */
    public function closeOnSelect(bool $condition = true): static
    {
        $this->closeOnSelect = $condition;

        return $this;
    }

    /**
     * IANA timezone the Today preset (and the calendar's initial month) reads
     * the clock in. Defaults to Laravel's `app.timezone` — the implicit
     * timezone of every naive value this field stores.
     */
    public function timezone(string $timezone): static
    {
        $this->pickerTimezone = $timezone;

        return $this;
    }

    /**
     * Opt out of the shadcn calendar and render two native date inputs.
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
     * Number of months shown side by side (default 1).
     */
    public function numberOfMonths(int $count): static
    {
        $this->numberOfMonths = max(1, $count);

        return $this;
    }

    public function weekdayFormat(string $format): static
    {
        $this->weekdayFormat = $format;

        return $this;
    }

    public function fixedWeeks(bool $condition = true): static
    {
        $this->fixedWeeks = $condition;

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

    /**
     * @return array{numberOfMonths: int, weekdayFormat: ?string, fixedWeeks: bool, weekStartsOn: ?int}
     */
    protected function rangeConfig(): array
    {
        return [
            'numberOfMonths' => $this->numberOfMonths,
            'weekdayFormat'  => $this->weekdayFormat,
            'fixedWeeks'     => $this->fixedWeeks,
            'weekStartsOn'   => null,
        ];
    }
}
