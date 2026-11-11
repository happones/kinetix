<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms;

use Happones\Kinetix\Support\Concerns\ScopedToTeam;
use Happones\Kinetix\Support\ConfigCallback;
use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Bounds a remote-search query with the scope and query modifier a field or
 * filter sealed into its descriptor, so the search endpoint and the selected
 * label lookup restrict records identically.
 *
 * Public for hosts: `bindToTeam()` (bound a resource query you override) and
 * `flush()` (after migrating on another node). Everything else is internal.
 */
final class SearchScope
{
    /** @var array<string, bool> Whether a table has the team column, per connection and table. */
    private static array $columns = [];

    private const VERSION_KEY = 'kinetix.search.schema';

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model> $builder
     * @param  array<array-key, mixed>                                $constraints column => value (null = whereNull)
     * @param  bool                                                   $teamScope   false when the field opted out (`withoutTeamScope()`)
     * @param  bool                                                   $withGlobal  rows with no team stay visible (`teamScopeIncludesGlobal()`)
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public static function apply(Builder $builder, array $constraints, mixed $modifier = null, bool $teamScope = true, bool $withGlobal = false): Builder
    {
        if ($teamScope) {
            self::bindToTeam($builder, $constraints, $withGlobal);
        }

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

        if ($callback !== null) {
            self::modify($builder, $callback);
        }

        return $builder;
    }

    /**
     * Apply a query modifier as a query scope: Eloquent nests the wheres a
     * scope adds in their own group when they contain an OR, so an `orWhere`
     * in the modifier can only widen that group, never escape the constraints
     * and the team bound already on the query. Joins and ordering the modifier
     * adds are kept as they are.
     *
     * @param Builder<covariant Model> $builder
     */
    public static function modify(Builder $builder, callable $callback): void
    {
        $builder->withGlobalScope(self::class.'#'.bin2hex(random_bytes(4)), static function (Builder $query) use ($callback): void {
            $base   = $query->getQuery();
            $before = count($base->wheres);
            $cap    = $base->limit;

            $callback($query);

            // Eloquent joins the nested group with the boolean of the first
            // where it holds: a modifier that opens with `orWhere` would be
            // OR-ed with everything before it, so the group is ANDed instead.
            // The negation of `whereNot` / `orWhereNot` lives in the same
            // boolean (`and not` / `or not`) and is kept.
            if (isset($base->wheres[$before])) {
                $boolean = (string) $base->wheres[$before]['boolean'];

                $base->wheres[$before]['boolean'] = str_contains($boolean, 'not') ? 'and not' : 'and';
            }

            // The caller's cap (options limit, the search endpoint's 20) still
            // wins over a larger limit the modifier sets.
            if ($cap !== null && ($base->limit === null || $base->limit > $cap)) {
                $base->limit = $cap;
            }
        });
    }

    /**
     * Forget the in-process answers only (the request/job state reset calls
     * this, so a long-lived worker re-reads the cache store).
     */
    public static function flushMemo(): void
    {
        self::$columns = [];
    }

    /**
     * Forget which tables were found to have the team column, in this process
     * and in the cache store. Runs after every migration on the node that
     * migrated; on other nodes the store entries expire after
     * `kinetix.search.schema_cache_ttl` (or call this once after deploying).
     */
    public static function flush(): void
    {
        self::flushMemo();

        try {
            Cache::forever(self::VERSION_KEY, bin2hex(random_bytes(4)));
        } catch (\Throwable) {
            // No usable cache store: the in-process memo is all there is.
        }
    }

    /**
     * Bound the query to the active team, in this order:
     *
     *  1. nothing with teams off or `kinetix.search.team_scope` false;
     *  2. nothing when the field's own constraints already name the team column;
     *  3. a model using {@see ScopedToTeam}, when its module is team-scoped:
     *     its team rows (`forCurrentTeam()`), plus the global ones
     *     (`forCurrentTeamOrGlobal()`) when the model declares them shared
     *     (`kinetixTeamRowsIncludeGlobal()`) or `$withGlobal`;
     *  4. the configured spatie role model: the team's roles plus the global
     *     ones, the visibility of `AssignableRoles::query()`;
     *  5. the `kinetix.search.team_column` column when the table has one: the
     *     team's rows only, plus the NULL ones with `$withGlobal`. A table
     *     without it (a shared catalog) is left alone.
     *
     * The team is the membership-checked {@see KinetixTeams::currentTeamKey()}
     * (a foreign `{current_team}` segment aborts 404; without a segment it is
     * the user's current team), and fails closed: no team resolved matches
     * no row at all (the role model is the exception: it keeps the global
     * roles, like `AssignableRoles::query()`).
     *
     * Public so a resource that overrides `getEloquentQuery()` can bound its
     * own query with the same rules.
     *
     * @param Builder<covariant Model> $builder
     * @param array<array-key, mixed>  $constraints
     */
    public static function bindToTeam(Builder $builder, array $constraints, bool $withGlobal = false): void
    {
        if (! config('kinetix.teams', false) || ! config('kinetix.search.team_scope', true)) {
            return;
        }

        $model   = $builder->getModel();
        $scoped  = in_array(ScopedToTeam::class, class_uses_recursive($model), true);
        $column  = $scoped ? 'team_id' : (string) config('kinetix.search.team_column', 'team_id');
        $roleKey = self::roleTeamColumn($model);

        foreach (array_keys($constraints) as $explicit) {
            if ($explicit === $column || $explicit === $model->qualifyColumn($column)) {
                return;
            }

            if ($roleKey !== null && ($explicit === $roleKey || $explicit === $model->qualifyColumn($roleKey))) {
                return;
            }
        }

        if ($scoped) {
            if (! KinetixTeams::enabledFor($model::class::kinetixTeamModule())) {
                return;
            }

            $teamKey = KinetixTeams::currentTeamKey();

            if ($teamKey === null) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $shared = $withGlobal || $model::class::kinetixTeamRowsIncludeGlobal();

            if ($shared) {
                /** @phpstan-ignore method.notFound */
                $builder->forCurrentTeamOrGlobal();

                return;
            }

            /** @phpstan-ignore method.notFound */
            $builder->forCurrentTeam();

            return;
        }

        if ($roleKey !== null) {
            $teamKey   = KinetixTeams::currentTeamKey();
            $qualified = $model->qualifyColumn($roleKey);

            $builder->where(function (Builder $inner) use ($qualified, $teamKey): void {
                $inner->whereNull($qualified);

                if ($teamKey !== null) {
                    $inner->orWhere($qualified, $teamKey);
                }
            });

            return;
        }

        if (! self::hasColumn($model, $column)) {
            return;
        }

        $teamKey = KinetixTeams::currentTeamKey();

        if ($teamKey === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $qualified = $model->qualifyColumn($column);

        $withGlobal
            ? $builder->where(fn (Builder $inner) => $inner->where($qualified, $teamKey)->orWhereNull($qualified))
            : $builder->where($qualified, $teamKey);
    }

    /**
     * The team column of the configured role model when this is it and
     * spatie's teams mode is on, else null.
     */
    private static function roleTeamColumn(Model $model): ?string
    {
        $role = config('permission.models.role');

        if (! is_string($role) || ! $model instanceof $role || ! (bool) config('permission.teams', false)) {
            return null;
        }

        return (string) (config('permission.column_names.team_foreign_key') ?? 'team_id');
    }

    private static function hasColumn(Model $model, string $column): bool
    {
        $connection = $model->getConnection();
        $table      = $model->getTable();
        $key        = $connection->getName().'|'.$table.'|'.$column;

        if (isset(self::$columns[$key])) {
            return self::$columns[$key];
        }

        $lookup = static fn (): bool => $connection->getSchemaBuilder()->hasColumn($table, $column);

        try {
            $version = Cache::get(self::VERSION_KEY, '0');
            $ttl     = config('kinetix.search.schema_cache_ttl', 3600);
            $found   = $ttl === null
                ? $lookup()
                : (bool) Cache::remember(self::VERSION_KEY.'.'.$version.'.'.$key, (int) $ttl, $lookup);
        } catch (\Throwable) {
            $found = $lookup();
        }

        return self::$columns[$key] = $found;
    }
}
