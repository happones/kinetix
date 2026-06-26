<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Filters a date column by ISO week. Value is an 'o-\WW' string (the native
 * <input type="week"> format, e.g. "2026-W25"). Matches rows whose date falls
 * within that ISO week (Monday–Sunday).
 */
class WeekFilter extends Filter
{
    protected ?string $attribute = null;

    protected bool $useCalendar = true;

    protected ?string $locale = null;

    protected ?string $minValue = null;

    protected ?string $maxValue = null;

    protected function getType(): string
    {
        return 'week';
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
            'locale'      => $this->locale,
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

        if (! is_string($value) || ! str_contains($value, '-W')) {
            return;
        }

        [$year, $week] = explode('-W', $value);
        $start         = Carbon::now()->setISODate((int) $year, (int) $week, 1);
        $end           = (clone $start)->addDays(6);
        $column        = $this->attribute ?? $this->name;

        // whereDate casts the column to a date, so this works for both date and
        // datetime columns (a datetime bound would exclude same-day times).
        $query->whereDate($column, '>=', $start->toDateString())
            ->whereDate($column, '<=', $end->toDateString());
    }
}
