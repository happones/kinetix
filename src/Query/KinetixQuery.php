<?php

declare(strict_types=1);

namespace Happones\Kinetix\Query;

use Closure;
use DateTime;
use Happones\Kinetix\Support\EloquentRelation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;

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
     * Columns are a plain list (every one matched with `$default`) or a map of
     * `column => SearchMode|Closure`; list entries and map entries can mix (a
     * string value is always a column name, even under a string key). A
     * closure receives `(Builder $query, string $term)` and runs INSIDE the
     * group, so an `orWhere` in it cannot widen past the caller's constraints;
     * it must bind the term itself, and only its `where` constraints survive
     * (joins and orders are dropped by the nested group: use `whereHas`,
     * `whereExists` or `whereIn` subqueries). A spec that is neither a
     * `SearchMode` nor a closure throws `InvalidArgumentException`.
     *
     * When columns were given but the term cannot match any of them (an
     * `Exact` search for letters against an integer key), the search matches
     * nothing rather than everything. No columns at all leaves the query as it
     * is.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     * @param  array<int|string, mixed>                               $columns Names, or `column => SearchMode|Closure`
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public static function search(Builder $query, string $term, array $columns, SearchMode $default = SearchMode::Contains): Builder
    {
        $term  = trim($term);
        $specs = [];

        foreach ($columns as $key => $value) {
            // An associative list of names (`['c' => 'code']`) has always
            // searched its VALUES: a string value is a column in the default
            // mode, and only a SearchMode or Closure is a spec for the key.
            $isName = is_int($key) || is_string($value);
            $column = $isName ? $value : $key;
            $spec   = $isName ? $default : $value;

            if (! is_string($column)) {
                throw new InvalidArgumentException('A search column must be a string name.');
            }

            if (! ($spec instanceof SearchMode || $spec instanceof Closure)) {
                throw new InvalidArgumentException(sprintf(
                    'The search spec of "%s" must be a SearchMode or a Closure; got %s.',
                    $column,
                    get_debug_type($spec),
                ));
            }

            if ($column === '') {
                continue;
            }

            $specs[] = [$column, $spec];
        }

        if ($term === '' || $specs === []) {
            return $query;
        }

        return $query->where(function (Builder $group) use ($specs, $term): void {
            $applied = false;

            foreach ($specs as [$column, $spec]) {
                if ($spec instanceof Closure) {
                    // A closure that adds no where leaves no group behind, so
                    // it must not count as a match: an all-empty search stays
                    // closed like the Exact path.
                    $added = false;

                    $group->orWhere(static function (Builder $inner) use ($spec, $term, &$added): void {
                        $spec($inner, $term);

                        $added = $inner->getQuery()->wheres !== [];
                    });

                    $applied = $added || $applied;

                    continue;
                }

                $applied = self::orMatch($group, $column, $spec, $term) || $applied;
            }

            if (! $applied) {
                $group->whereRaw('1 = 0');
            }
        });
    }

    /**
     * Add one column's OR branch to the group. False when the term cannot match
     * the column (nothing was added).
     *
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $group
     */
    protected static function orMatch(Builder $group, string $column, SearchMode $mode, string $term): bool
    {
        if (! str_contains($column, '.')) {
            if (! self::guardAllows($group->getModel(), $column, $mode, $term)) {
                return false;
            }

            self::predicate($group, $column, $mode, $term, 'or');

            return true;
        }

        [$relation, $attribute] = self::splitRelation($column);
        $model                  = $group->getModel();
        $first                  = explode('.', $relation)[0];

        // `table.column` naming the model's own table is a plain column of it:
        // guard it like one (the qualified form is kept by the predicate).
        if ($relation === $model->getTable() && ! method_exists($model, $first)) {
            if (! self::guardAllows($model, $attribute, $mode, $term)) {
                return false;
            }

            self::predicate($group, $attribute, $mode, $term, 'or');

            return true;
        }

        // A dotted name whose first segment is NOT a relation is a
        // table-qualified column of another table (e.g. a pivot column the
        // Table translated to `pivot_table.role` against the joined query) —
        // match it directly instead of a whereHas that would throw. Nothing
        // is known about its type, so `Exact` does not guard it.
        if (! method_exists($model, $first)) {
            self::predicate($group, $column, $mode, $term, 'or');

            return true;
        }

        if ($mode === SearchMode::Exact) {
            $related = self::relatedModel($model, $relation);

            if ($related !== null && ! self::guardAllows($related, $attribute, $mode, $term)) {
                return false;
            }
        }

        $group->orWhereHas(
            $relation,
            static fn (Builder $inner) => self::predicate($inner, $attribute, $mode, $term, 'and'),
        );

        return true;
    }

    /**
     * One column's predicate in the given mode.
     *
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     */
    protected static function predicate(Builder $query, string $column, SearchMode $mode, string $term, string $boolean): void
    {
        // Qualify plain columns with the model's table: under a joined base
        // query (a BelongsToMany relation table joins its pivot) a bare shared
        // name (`id`, `created_at`) is ambiguous SQL and 500s on search.
        $qualified = str_contains($column, '.') ? $column : $query->getModel()->qualifyColumn($column);

        if ($mode === SearchMode::Exact) {
            $value = self::exactValue($query->getModel(), $column, $term) ?? $term;

            // PostgreSQL infers an untyped parameter's type from the column,
            // so a number past int4 would fail ("out of range for type
            // integer") instead of simply not matching. Binding the primary
            // key as bigint keeps the btree index (int4 and int8 share an
            // operator family). Other integer columns keep the plain bind (a
            // cast column may be text) and the guard bounds the value to int4.
            if (is_int($value) && self::driver($query) === 'pgsql' && self::isIntegerKey($query->getModel(), $column)) {
                $query->whereRaw($query->getQuery()->getGrammar()->wrap($qualified).' = cast(? as bigint)', [$value], $boolean);

                return;
            }

            $query->where($qualified, '=', $value, $boolean);

            return;
        }

        if ($mode === SearchMode::FullText && self::supportsFullText($query)) {
            $language = config('kinetix.search.full_text.language', 'english');

            $query->whereFullText(
                $qualified,
                $term,
                ['language' => is_string($language) && $language !== '' ? $language : 'english'],
                $boolean,
            );

            return;
        }

        $value = $mode === SearchMode::Prefix
            ? self::escapeLike($term).'%'
            : '%'.self::escapeLike($term).'%';

        self::like($query, $column, $qualified, $value, $boolean);
    }

    /**
     * A `LIKE ? ESCAPE '!'` predicate. Raw because Laravel's `where(…, 'like',
     * …)` emits no ESCAPE clause, which would leave the escaped wildcards
     * matching literal `!%` sequences instead.
     *
     * On PostgreSQL `LIKE` does not exist for numbers and dates
     * (`operator does not exist: bigint ~~ unknown`), so a column the model
     * knows is not text is cast to text first. Text columns are never cast:
     * that would stop a prefix search using its index.
     *
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     */
    protected static function like(Builder $query, string $column, string $qualified, string $value, string $boolean): void
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap($qualified);

        if (! str_contains($column, '.') && self::driver($query) === 'pgsql' && self::isNonText($query->getModel(), $column)) {
            $wrapped = "cast({$wrapped} as text)";
        }

        $query->whereRaw($wrapped." like ? escape '".self::LIKE_ESCAPE."'", [$value], $boolean);
    }

    /** Whether the term can be searched in this column in this mode (only `Exact` can refuse). */
    protected static function guardAllows(Model $model, string $column, SearchMode $mode, string $term): bool
    {
        return $mode !== SearchMode::Exact || self::exactValue($model, $column, $term) !== null;
    }

    /**
     * The value an `Exact` search binds for this column, or null when the term
     * cannot be a value of it (the column is skipped): an integer in range for
     * a bigint, a finite number, a strictly parsed date, a valid UUID / ULID.
     * Columns without a known type take the term as text.
     */
    protected static function exactValue(Model $model, string $column, string $term): string|int|null
    {
        if (self::isUniqueIdColumn($model, $column)) {
            return (new \ReflectionMethod($model, 'isValidUniqueId'))->invoke($model, $term) ? $term : null;
        }

        $type = $column === $model->getKeyName() && $model->getKeyType() === 'int'
            ? 'int'
            : self::castType($model, $column);

        return match ($type) {
            'int'      => self::integer($term, $column === $model->getKeyName()),
            'numeric'  => is_numeric($term) && is_finite((float) $term) ? $term : null,
            'date'     => self::strictDate($term, false),
            'datetime' => self::strictDate($term, true),
            default    => $term,
        };
    }

    /**
     * An integer term. The key may be a bigint (bound as such on PostgreSQL);
     * any other integer column is bounded to int4, so an `integer` column
     * cannot overflow. A `smallint` column still can past 32767: use
     * `searchable(using:)` for one.
     */
    protected static function integer(string $term, bool $isKey): ?int
    {
        if (preg_match('/^-?\d{1,18}$/', $term) !== 1) {
            return null;
        }

        $value = (int) $term;

        if (! $isKey && ($value < -2147483648 || $value > 2147483647)) {
            return null;
        }

        return $value;
    }

    protected static function isIntegerKey(Model $model, string $column): bool
    {
        return $column === $model->getKeyName() && $model->getKeyType() === 'int';
    }

    /**
     * `Y-m-d`, `Y-m-d H:i` or `Y-m-d H:i:s`, round-tripped so `2026`,
     * `monday` or `2026-02-30` are refused instead of reaching the engine.
     * A datetime matches the exact timestamp (midnight for a date-only term);
     * a date column matches a term with no time of day only.
     */
    protected static function strictDate(string $term, bool $withTime): ?string
    {
        foreach (['Y-m-d' => 'Y-m-d', 'Y-m-d H:i' => 'Y-m-d H:i', 'Y-m-d H:i:s' => 'Y-m-d H:i:s'] as $format) {
            $parsed = DateTime::createFromFormat('!'.$format, $term);

            if ($parsed === false || $parsed->format($format) !== $term) {
                continue;
            }

            if ($withTime) {
                return $parsed->format('Y-m-d H:i:s');
            }

            return $parsed->format('H:i:s') === '00:00:00' ? $parsed->format('Y-m-d') : null;
        }

        return null;
    }

    protected static function isNonText(Model $model, string $column): bool
    {
        if (self::isUniqueIdColumn($model, $column)) {
            // A native `uuid` column has no LIKE either (a ULID column is text,
            // and a cast on it only costs the index a prefix search would use).
            return isset(class_uses_recursive($model)[HasUuids::class]);
        }

        if ($column === $model->getKeyName()) {
            return $model->getKeyType() === 'int';
        }

        return self::castType($model, $column) !== null;
    }

    protected static function isUniqueIdColumn(Model $model, string $column): bool
    {
        $traits = class_uses_recursive($model);

        if (! isset($traits[HasUuids::class]) && ! isset($traits[HasUlids::class])) {
            return false;
        }

        $ids = call_user_func([$model, 'uniqueIds']);

        return in_array($column, (array) $ids, true);
    }

    /**
     * What kind of value a cast column holds: `int`, `numeric`, `date`, or null
     * for text (and anything unknown).
     */
    protected static function castType(Model $model, string $column): ?string
    {
        $cast = $model->getCasts()[$column] ?? null;

        // Timestamps (`created_at`) and declared dates are dates without a cast.
        if (! is_string($cast)) {
            return in_array($column, $model->getDates(), true) ? 'datetime' : null;
        }

        $cast = strtolower(explode(':', $cast)[0]);

        return match (true) {
            in_array($cast, ['int', 'integer'], true)                              => 'int',
            in_array($cast, ['real', 'float', 'double', 'decimal'], true)          => 'numeric',
            in_array($cast, ['date', 'immutable_date'], true)                      => 'date',
            in_array($cast, ['datetime', 'immutable_datetime', 'timestamp'], true) => 'datetime',
            default                                                                => null,
        };
    }

    /**
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     */
    protected static function driver(Builder $query): string
    {
        $connection = $query->getQuery()->getConnection();

        return method_exists($connection, 'getDriverName') ? (string) $connection->getDriverName() : '';
    }

    /**
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     */
    protected static function supportsFullText(Builder $query): bool
    {
        return in_array(self::driver($query), ['mysql', 'mariadb', 'pgsql'], true);
    }

    /** The model at the end of a (dotted) relation path, or null when it cannot be walked. */
    protected static function relatedModel(Model $model, string $path): ?Model
    {
        foreach (explode('.', $path) as $name) {
            $relation = EloquentRelation::resolve($model, $name);

            if ($relation === null) {
                return null;
            }

            $model = $relation->getRelated();
        }

        return $model;
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

        return $relations === [] ? $query : self::withPreservingConstraints($query, array_keys($relations));
    }

    /**
     * Queue eager-loads without loosening what the query already loads.
     *
     * Eloquent's with() replaces an eager-load of the same name: re-stating
     * `notes` — or reaching `author.company`, which re-queues `author` as a
     * plain load — silently dropped a constraint the query set (approved notes
     * only, a tenant filter on the author). Here a relation the query already
     * loads keeps its constraint; one passed as `name => Closure` here still
     * applies, since that is a constraint asked for on purpose.
     *
     * @param  array<int|string, mixed> $relations
     * @return Builder<Model>
     */
    public static function withPreservingConstraints(Builder $query, array $relations): Builder
    {
        $existing = $query->getEagerLoads();
        $loads    = $query->with($relations)->getEagerLoads();

        foreach ($existing as $name => $constraint) {
            if (! (array_key_exists($name, $relations) && $relations[$name] instanceof Closure)) {
                $loads[$name] = $constraint;
            }
        }

        return $query->setEagerLoads($loads);
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
