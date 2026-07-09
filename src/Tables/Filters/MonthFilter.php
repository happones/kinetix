<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Filters;

use Happones\Kinetix\Support\KinetixLocale;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filters a date column by a calendar month. Value is 'Y-m' (e.g. "2026-06").
 */
class MonthFilter extends Filter
{
    protected ?string $attribute = null;

    protected bool $useCalendar = true;

    protected ?string $locale = null;

    protected ?string $minValue = null;

    protected ?string $maxValue = null;

    protected function getType(): string
    {
        return 'month';
    }

    public function attribute(string $attribute): static
    {
        $this->attribute = $attribute;

        return $this;
    }

    public function native(bool $condition = true): static
    {
        $this->useCalendar = ! $condition;

        return $this;
    }

    public function locale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function minValue(string $value): static
    {
        $this->minValue = $value;

        return $this;
    }

    public function maxValue(string $value): static
    {
        $this->maxValue = $value;

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

        if (! is_string($value) || ! str_contains($value, '-')) {
            return;
        }

        [$year, $month] = explode('-', $value);
        $column         = $this->attribute ?? $this->name;

        $query->whereYear($column, (int) $year)->whereMonth($column, (int) $month);
    }
}
