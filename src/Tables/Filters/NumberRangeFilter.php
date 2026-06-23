<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Filters;

use Illuminate\Database\Eloquent\Builder;

class NumberRangeFilter extends Filter
{
    protected ?string $attribute = null;

    protected function getType(): string
    {
        return 'number-range';
    }

    /**
     * The numeric column to filter by. Defaults to the filter name.
     */
    public function attribute(string $attribute): static
    {
        $this->attribute = $attribute;

        return $this;
    }

    /**
     * @param array{min?: string|int|float|null, max?: string|int|float|null}|mixed $value
     */
    public function apply(Builder $query, mixed $value): void
    {
        if ($this->query !== null) {
            ($this->query)($query, $value);

            return;
        }

        if (! is_array($value)) {
            return;
        }

        $attribute = $this->attribute ?? $this->name;
        $min       = $value['min']    ?? null;
        $max       = $value['max']    ?? null;

        if ($min !== null && $min !== '') {
            $query->where($attribute, '>=', $min);
        }

        if ($max !== null && $max !== '') {
            $query->where($attribute, '<=', $max);
        }
    }
}
