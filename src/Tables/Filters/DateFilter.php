<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Filters;

use Illuminate\Database\Eloquent\Builder;

class DateFilter extends Filter
{
    protected ?string $attribute = null;

    protected string $operator = '=';

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
