<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms;

use Happones\Kinetix\Support\ConfigCallback;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bounds a remote-search query with the scope and query modifier a field or
 * filter sealed into its descriptor, so the search endpoint and the selected
 * label lookup restrict records identically.
 *
 * @internal
 */
final class SearchScope
{
    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model> $builder
     * @param  array<array-key, mixed>                                $constraints column => value (null = whereNull)
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public static function apply(Builder $builder, array $constraints, mixed $modifier = null): Builder
    {
        foreach ($constraints as $column => $value) {
            if (! is_string($column)) {
                continue;
            }

            $value === null
                ? $builder->whereNull($column)
                : $builder->where($column, $value);
        }

        // Only an invokable class-string (or callable array) is ever resolved:
        // the descriptor round-trips through the browser, so it can't carry a
        // closure, and the value is looked up rather than trusted as input.
        $callback = ConfigCallback::resolve($modifier);

        // Applied as a query scope: Eloquent nests the wheres a scope adds in
        // their own group when they contain an OR, so an `orWhere` in the
        // modifier can only widen that group, never escape the constraints
        // above. Joins and ordering the modifier adds are kept as they are.
        if ($callback !== null) {
            $builder->withGlobalScope(self::class, static function (Builder $query) use ($callback): void {
                $callback($query);
            });
        }

        return $builder;
    }
}
