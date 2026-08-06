<?php

declare(strict_types=1);

namespace Happones\Kinetix\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The query primitives every Kinetix reader shares: text search, eager-load
 * derivation, and relation sorting.
 *
 * These were reimplemented in five places (the table, the select-field search,
 * spotlight, the API-log and webhook-log feeds), each subtly different — one
 * escaped nothing, another grouped its `orWhere`s incorrectly. Centralizing
 * them makes the behavior one thing to reason about and one thing to fix.
 *
 * **Tenancy is deliberately absent.** Kinetix does not know the host's team
 * schema, so the caller supplies an already-scoped base query
 * (`Resource::getEloquentQuery()` is the documented seam). Putting a `team_id`
 * filter in here would either guess the column or silently do nothing.
 */
final class KinetixQuery
{
    /**
     * The LIKE escape character.
     *
     * Not a backslash on purpose: MySQL processes backslash escapes inside
     * string literals, so `ESCAPE '\'` is a syntax error there, while SQLite and
     * Postgres need the clause spelled out because they have no default escape
     * character at all. `!` is a plain literal in every engine.
     */
    private const LIKE_ESCAPE = '!';

    /**
     * Escape the LIKE wildcards in user input.
     *
     * Bindings stop injection, but not wildcards: a search for `100%` otherwise
     * becomes `%100%%`, which matches every row and scans the whole table.
     */
    public static function escapeLike(string $term): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'],
            $term,
        );
    }

    /**
     * Apply a grouped OR search across the given columns.
     *
     * Grouping matters: without the wrapping closure the ORs would escape any
     * `where()` the caller already applied (a tenant filter, most importantly)
     * and widen the result set. Dot-notation columns (`author.name`) search the
     * relation via `whereHas`.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     * @param  array<int, string>                                     $columns
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public static function search(Builder $query, string $term, array $columns): Builder
    {
        $term    = trim($term);
        $columns = array_values(array_filter($columns, static fn (string $c): bool => $c !== ''));

        if ($term === '' || $columns === []) {
            return $query;
        }

        $like = '%'.self::escapeLike($term).'%';

        return $query->where(function (Builder $group) use ($columns, $like): void {
            foreach ($columns as $column) {
                if (! str_contains($column, '.')) {
                    static::like($group, $column, $like, 'or');

                    continue;
                }

                [$relation, $attribute] = static::splitRelation($column);

                // A dotted name whose first segment is NOT a relation is a
                // table-qualified column (e.g. a pivot column the Table
                // translated to `pivot_table.role` against the joined query) —
                // LIKE it directly instead of a whereHas that would throw.
                if (! method_exists($group->getModel(), explode('.', $relation)[0])) {
                    static::like($group, $column, $like, 'or');

                    continue;
                }

                $group->orWhereHas(
                    $relation,
                    static fn (Builder $related) => static::like($related, $attribute, $like, 'and'),
                );
            }
        });
    }

    /**
     * A `LIKE ? ESCAPE '!'` predicate. Raw because Laravel's `where(…, 'like',
     * …)` emits no ESCAPE clause, which would leave the escaped wildcards
     * matching literal `!%` sequences instead.
     *
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     */
    protected static function like(Builder $query, string $column, string $value, string $boolean): void
    {
        // Qualify plain columns with the model's table: under a joined base
        // query (a BelongsToMany relation table joins its pivot) a bare shared
        // name (`id`, `created_at`) is ambiguous SQL and 500s on search.
        if (! str_contains($column, '.')) {
            $column = $query->getModel()->qualifyColumn($column);
        }

        $sql = $query->getQuery()->getGrammar()->wrap($column)." like ? escape '".self::LIKE_ESCAPE."'";

        $query->whereRaw($sql, [$value], $boolean);
    }

    /**
     * Eager-load the relations behind dot-notation column names.
     *
     * Reading `author.name` per row through `data_get()` lazy-loads the relation
     * once per row — the N+1 the dot-notation feature exists to avoid. The
     * relations are derived from the columns themselves, so it needs no
     * configuration and can't drift from what is actually rendered.
     * Already-loaded relations are not re-added.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     * @param  array<int, string>                                     $columns
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public static function eagerLoad(Builder $query, array $columns): Builder
    {
        $relations = [];
        $model     = $query->getModel();

        foreach ($columns as $column) {
            if (! str_contains($column, '.')) {
                continue;
            }

            // Everything but the last segment is the relation path
            // (`author.company.name` → `author.company`).
            $segments = explode('.', $column);
            array_pop($segments);
            $path = implode('.', $segments);

            // Only load what the model actually declares — a dot in a column
            // name can also mean a JSON path (`meta.color`), which is not a
            // relation and would throw.
            if ($path === '' || ! method_exists($model, explode('.', $path)[0])) {
                continue;
            }

            $relations[$path] = true;
        }

        $relations = array_diff_key($relations, $query->getEagerLoads());

        return $relations === [] ? $query : $query->with(array_keys($relations));
    }

    /**
     * Sort by a `relation.column` key through a correlated subquery — no join,
     * so no row duplication and no column collisions.
     *
     * Supports single-level BelongsTo and HasOne; anything else is skipped
     * rather than risking a wrong query.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     * @return bool                                                   Whether the sort was applied.
     */
    public static function sortByRelation(Builder $query, string $key, string $direction): bool
    {
        [$relationName, $attribute] = self::splitRelation($key);

        if ($relationName === '' || str_contains($attribute, '.') || ! method_exists($query->getModel(), $relationName)) {
            return false;
        }

        $relation = $query->getModel()->{$relationName}();

        if (! $relation instanceof Relation) {
            return false;
        }

        $related = $relation->getRelated();
        $sub     = $related->newQuery()
            ->select($related->qualifyColumn($attribute))
            ->limit(1);

        if ($relation instanceof BelongsTo) {
            $sub->whereColumn($relation->getQualifiedOwnerKeyName(), $relation->getQualifiedForeignKeyName());
        } elseif ($relation instanceof HasOne) {
            $sub->whereColumn($relation->getQualifiedForeignKeyName(), $relation->getQualifiedParentKeyName());
        } else {
            return false;
        }

        $query->orderBy($sub, self::direction($direction));

        return true;
    }

    /**
     * Normalize a sort direction from untrusted input.
     */
    public static function direction(mixed $value): string
    {
        return strtolower((string) $value) === 'desc' ? 'desc' : 'asc';
    }

    /**
     * `author.name` → `['author', 'name']`; `author.company.name` →
     * `['author.company', 'name']`.
     *
     * @return array{0: string, 1: string}
     */
    protected static function splitRelation(string $column): array
    {
        $segments  = explode('.', $column);
        $attribute = (string) array_pop($segments);

        return [implode('.', $segments), $attribute];
    }
}
