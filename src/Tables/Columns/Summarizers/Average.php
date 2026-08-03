<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Columns\Summarizers;

use Illuminate\Database\Eloquent\Builder;

/**
 * The average (mean) of all values in the column's dataset.
 */
class Average extends Summarizer
{
    /**
     * @return array<string, string>
     */
    public function aggregateExpressions(string $column): array
    {
        return ['value' => "avg({$column})"];
    }

    /**
     * An empty set averages to null; keep the pre-batching behaviour of showing
     * a zero rather than a blank.
     *
     * @param array<string, mixed> $values
     */
    protected function formatValues(array $values): string
    {
        return $this->format($values['value'] ?? 0);
    }

    protected function compute(Builder $query, string $column): mixed
    {
        return $query->avg($column) ?? 0;
    }
}
