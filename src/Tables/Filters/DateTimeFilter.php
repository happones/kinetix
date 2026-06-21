<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Filters;

use Illuminate\Database\Eloquent\Builder;

class DateTimeFilter extends Filter
{
    protected ?string $attribute = null;

    protected string $operator = '>=';

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
