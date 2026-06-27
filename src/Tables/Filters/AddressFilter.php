<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Filters;

use Illuminate\Database\Eloquent\Builder;

/**
 * Filters rows by a free-text address match across one or more columns
 * (city, state, postal code, country, …) with OR LIKE. Set the searchable
 * columns with columns(); defaults to the filter name.
 *
 *     AddressFilter::make('address')->columns(['city', 'state', 'postal_code', 'country']);
 */
class AddressFilter extends Filter
{
    /**
     * @var array<int, string>
     */
    protected array $columns = [];

    protected function getType(): string
    {
        return 'address';
    }

    /**
     * @param array<int, string> $columns
     */
    public function columns(array $columns): static
    {
        $this->columns = array_values($columns);

        return $this;
    }

    public function apply(Builder $query, mixed $value): void
    {
        if ($this->query !== null) {
            ($this->query)($query, $value);

            return;
        }

        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $columns = $this->columns !== [] ? $this->columns : [$this->name];
        $term    = '%'.trim($value).'%';

        $query->where(function (Builder $q) use ($columns, $term): void {
            foreach ($columns as $column) {
                $q->orWhere($column, 'like', $term);
            }
        });
    }
}
