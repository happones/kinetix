<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;

/**
 * Resolves team scoping per module with tri-state inheritance: a module's
 * `kinetix.{module}.teams` set to `true`/`false` wins; `null` (the default)
 * inherits the global `kinetix.teams`. One switch covers the whole suite,
 * per-module overrides stay possible (e.g. team-scoped app, personal billing).
 */
class KinetixTeams
{
    /**
     * Whether the given module scopes its data per team.
     */
    public static function enabledFor(string $module): bool
    {
        $value = config("kinetix.{$module}.teams");

        if ($value === null) {
            return (bool) config('kinetix.teams', false);
        }

        return (bool) $value;
    }

    /**
     * Resolve the current team's PRIMARY KEY for data scoping. The
     * `{current_team}` route segment is the team's ROUTE key (the host may
     * route teams by slug/uuid — `Team::getRouteKeyName()`), so it must never
     * be stored as a team id directly. Resolution order:
     *
     *  - a Model bound to the param (host-registered binding) → its key;
     *  - a scalar segment → looked up through the authenticated user's teams
     *    relation (`kinetix.team_switcher.teams_relation`, default `teams`)
     *    by the related model's route key name — which doubles as the
     *    MEMBERSHIP check: a segment that isn't one of the user's teams
     *    aborts 404;
     *  - no relation on the user → the raw segment (legacy id-keyed fallback);
     *  - no segment → the user's `currentTeam` key.
     */
    public static function currentTeamKey(?Request $request = null): int|string|null
    {
        $request ??= request();
        $param = $request->route('current_team');

        if ($param instanceof Model) {
            return $param->getKey();
        }

        // Outside a routed HTTP request (queued jobs, console, unit tests) the
        // request has no user resolver — fall back to the auth guard.
        $user = $request->user() ?? auth()->user();

        if ($param !== null) {
            $relationName = (string) config('kinetix.team_switcher.teams_relation', 'teams');

            if ($user instanceof Model && method_exists($user, $relationName)) {
                $relation = $user->{$relationName}();

                if ($relation instanceof Relation) {
                    $related = $relation->getRelated();
                    $team    = $relation
                        ->where($related->qualifyColumn($related->getRouteKeyName()), $param)
                        ->first();

                    // Not one of the user's teams (or not a team at all).
                    abort_if($team === null, 404);

                    return $team->getKey();
                }
            }

            // No teams relation to resolve/verify against — legacy behaviour:
            // trust the segment as the key (id-routed teams). Membership is
            // the host's responsibility in this mode.
            return $param;
        }

        return $user?->currentTeam?->getKey();
    }
}
