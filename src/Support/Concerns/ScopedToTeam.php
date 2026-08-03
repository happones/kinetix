<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support\Concerns;

use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tenant scoping for a Kinetix model that owns a `team_id` column.
 *
 * Modules used to hand-roll this, which is how a `team_id` column ends up
 * declared, indexed and never written — the row is created without it and every
 * listing reads across tenants. Applying the trait makes the scope explicit and
 * **fails closed**: while the module is team-scoped the query is always
 * constrained, and an unresolvable team matches `NULL` rows rather than all of
 * them.
 */
trait ScopedToTeam
{
    /**
     * The `kinetix.{module}` whose `teams` flag governs this model.
     */
    abstract public static function kinetixTeamModule(): string;

    /**
     * Constrain a query to the active team (a no-op when the module is not
     * team-scoped).
     *
     * @param  Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForCurrentTeam(Builder $query): Builder
    {
        if (! KinetixTeams::enabledFor(static::kinetixTeamModule())) {
            return $query;
        }

        $column = $query->getModel()->qualifyColumn('team_id');
        $teamId = KinetixTeams::currentTeamKey();

        return $teamId === null
            ? $query->whereNull($column)
            : $query->where($column, $teamId);
    }

    /**
     * The value to stamp on a new row. `null` when the module is not
     * team-scoped, so single-tenant apps keep writing NULL.
     */
    public static function currentTeamId(): int|string|null
    {
        return KinetixTeams::keyFor(static::kinetixTeamModule());
    }
}
