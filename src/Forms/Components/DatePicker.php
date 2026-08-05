<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Support\KinetixLocale;

class DatePicker extends Field
{
    protected bool $useCalendar = true;

    protected ?string $dateLocale = null;

    protected bool $confirm = false;

    protected bool $showToday = false;

    protected bool $closeOnSelect = true;

    protected ?string $pickerTimezone = null;

    protected function getType(): string
    {
        return 'date-picker';
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
     * Commit only via an explicit Apply button: clicking a date updates a
     * DRAFT inside the popover; dismissing without applying discards it.
     */
    public function confirm(bool $condition = true): static
    {
        $this->confirm = $condition;

        return $this;
    }

    /**
     * Show a "Today" shortcut in the popover footer.
     */
    public function todayButton(bool $condition = true): static
    {
        $this->showToday = $condition;

        return $this;
    }

    /**
     * Whether picking a date closes the popover (default true — the shadcn
     * behavior). Pass false to keep it open, e.g. next to related fields.
     */
    public function closeOnSelect(bool $condition = true): static
    {
        $this->closeOnSelect = $condition;

        return $this;
    }

    /**
     * Opt out of the shadcn calendar and render a plain native date input.
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
     * @return array{useCalendar: bool, locale: ?string, minuteStep: int, hour12: bool, confirm: bool, showToday: bool, closeOnSelect: bool}
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
