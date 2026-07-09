<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Filters;

use Illuminate\Database\Eloquent\Builder;

class MultiSelectFilter extends SelectFilter
{
    protected function getType(): string
    {
        return 'multi-select';
    }

    /**
     * Match records whose attribute is any of the selected values (whereIn).
     *
     * @param array<int, mixed>|mixed $value
     */
    public function apply(Builder $query, mixed $value): void
    {
        if ($this->query !== null) {
            ($this->query)($query, $value);

            return;
        }

        $values = is_array($value)
            ? array_values(array_filter($value, fn ($v) => $v !== null && $v !== ''))
            : array_filter([$value], fn ($v) => $v !== null && $v !== '');

        if ($values === []) {
            return;
        }

        if ($this->relationshipName !== null) {
            $query->whereHas($this->relationshipName, fn (Builder $q) => $q->whereKey($values));

            return;
        }

        $query->whereIn($this->attribute ?? $this->name, $values);
    }
}
