<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Columns\Summarizers;

use Illuminate\Database\Eloquent\Builder;

/**
 * The total of all values in the column's dataset.
 */
class Sum extends Summarizer
{
    protected function compute(Builder $query, string $column): mixed
    {
        return $query->sum($column);
    }
}
