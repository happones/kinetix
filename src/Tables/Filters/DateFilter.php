<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Filters;

use Happones\Kinetix\Support\KinetixLocale;
use Illuminate\Database\Eloquent\Builder;

class DateFilter extends Filter
{
    protected ?string $attribute = null;

    protected string $operator = '=';

    protected bool $useCalendar = true;

    protected ?string $locale = null;

    protected ?string $minValue = null;

    protected ?string $maxValue = null;

    protected function getType(): string
    {
        return 'date';
    }

    /**
     * The date column to filter by. Defaults to the filter name.
     */
    public function attribute(string $attribute): static
    {
        $this->attribute = $attribute;

        return $this;
    }

    /**
     * Comparison operator applied to the date (=, >=, <=, >, <).
     */
    public function operator(string $operator): static
    {
        $this->operator = $operator;

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
        $this->locale = $locale;

        return $this;
    }

    /**
     * Earliest selectable date (ISO 'Y-m-d').
     */
    public function minValue(string $date): static
    {
        $this->minValue = $date;

        return $this;
    }

    /**
     * Latest selectable date (ISO 'Y-m-d').
     */
    public function maxValue(string $date): static
    {
        $this->maxValue = $date;

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
            'minValue'    => $this->minValue,
            'maxValue'    => $this->maxValue,
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

        $query->whereDate($this->attribute ?? $this->name, $this->operator, $value);
    }
}
