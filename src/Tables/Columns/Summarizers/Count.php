<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Columns\Summarizers;

use Illuminate\Database\Eloquent\Builder;

/**
 * The number of rows in the (optionally scoped) dataset. Pair with query() to
 * count only records passing a test, e.g. published posts.
 */
class Count extends Summarizer
{
    /**
     * @return array<string, string>
     */
    public function aggregateExpressions(string $column): array
    {
        return ['value' => 'count(*)'];
    }

    protected function compute(Builder $query, string $column): mixed
    {
        return $query->count();
    }
}
