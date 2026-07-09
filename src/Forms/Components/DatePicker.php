<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Support\KinetixLocale;

class DatePicker extends Field
{
    protected bool $useCalendar = true;

    protected ?string $dateLocale = null;

    protected function getType(): string
    {
        return 'date-picker';
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
