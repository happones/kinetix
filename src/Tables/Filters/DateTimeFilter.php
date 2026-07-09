<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Filters;

use Happones\Kinetix\Support\KinetixLocale;
use Illuminate\Database\Eloquent\Builder;

class DateTimeFilter extends Filter
{
    protected ?string $attribute = null;

    protected string $operator = '>=';

    protected bool $useCalendar = true;

    protected ?string $locale = null;

    protected int $minuteStep = 5;

    protected bool $hour12 = false;

    protected function getType(): string
    {
        return 'datetime';
    }

    /**
     * The datetime column to filter by. Defaults to the filter name.
     */
    public function attribute(string $attribute): static
    {
        $this->attribute = $attribute;

        return $this;
    }

    /**
     * Comparison operator applied to the datetime (>=, <=, =, >, <). Defaults to ">=".
     */
    public function operator(string $operator): static
    {
        $this->operator = $operator;

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
        $this->locale = $locale;

        return $this;
    }

    /**
     * Minute granularity for the time buttons (default 5).
     */
    public function minuteStep(int $step): static
    {
        $this->minuteStep = max(1, $step);

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
     * @return array<string, mixed>
     */
    protected function getExtraData(): array
    {
        return [
            'useCalendar' => $this->useCalendar,
            'locale'      => $this->locale ?? KinetixLocale::bcp47(),
            'minuteStep'  => $this->minuteStep,
            'hour12'      => $this->hour12,
        ];
    }

    public function apply(Builder $query, mixed $value): void
    {
        if ($this->query !== null) {
            ($this->query)($query, $value);

            return;
        }

        if ($value === null || $value === '') {
            return;
        }

        // Normalize the HTML datetime-local value (Y-m-dTH:i) to a SQL datetime.
        $value = str_replace('T', ' ', (string) $value);

        $query->where($this->attribute ?? $this->name, $this->operator, $value);
    }
}
