<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Filters;

use Illuminate\Database\Eloquent\Builder;

/**
 * Filters a date column by year. Value is a 'Y' string (e.g. "2026").
 */
class YearFilter extends Filter
{
    protected ?string $attribute = null;

    protected bool $useCalendar = true;

    protected ?string $minValue = null;

    protected ?string $maxValue = null;

    protected function getType(): string
    {
        return 'year';
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

        $query->whereYear($this->attribute ?? $this->name, (int) $value);
    }
}
