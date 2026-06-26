<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Columns\Summarizers;

use Illuminate\Database\Eloquent\Builder;

/**
 * The average (mean) of all values in the column's dataset.
 */
class Average extends Summarizer
{
    protected function compute(Builder $query, string $column): mixed
    {
        return $query->avg($column) ?? 0;
    }
}
