<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use Happones\Kinetix\Teams\TeamSwitcherManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteRegistrar;
use Illuminate\Support\Facades\Route;

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
     * The apex domain whose subdomains carry the team (`kinetix.tenancy.domain`,
     * e.g. `example.com` for `acme.example.com`), or null when the team rides in
     * the path (`/acme/...`). Only with teams on.
     */
    public static function domain(): ?string
    {
        $domain = config('kinetix.tenancy.domain');

        return config('kinetix.teams', false) && is_string($domain) && $domain !== '' ? $domain : null;
    }

    /**
     * Whether the team is read from the request host rather than a path segment.
     */
    public static function inHost(): bool
    {
        return static::domain() !== null;
    }

    /**
     * The path prefix of a team-scoped route group: `{current_team}/{prefix}`
     * with the team in the path, the bare prefix with it in the host or with
     * teams off.
     */
    public static function routePrefix(string $prefix): string
    {
        return config('kinetix.teams', false) && ! static::inHost() ? '{current_team}/'.$prefix : $prefix;
    }

    /**
     * A route group for team-scoped endpoints. With the team in the host it is
     * bound to `{parameter}.{domain}`, so the parameter still reaches the
     * routes — and everything reading `$request->route('current_team')` — as
     * it does from the path.
     *
     * @param array<int, string>|string $middleware
     */
    public static function routes(array|string $middleware, string $parameter = 'current_team'): RouteRegistrar
    {
        $routes = Route::middleware($middleware);

        return static::inHost() ? $routes->domain('{'.$parameter.'}.'.static::domain()) : $routes;
    }

    /**
     * The team key a MODULE should scope its data to: the active team when that
     * module is team-scoped, `null` otherwise.
     *
     * This is the one call every module should make. Resolving the team by hand
     * (`auth()->user()->currentTeam`) silently ignores the `{current_team}`
     * segment, so a request served for team B writes and reads team A's rows —
     * and skips the membership check {@see currentTeamKey()} performs.
     */
    public static function keyFor(string $module, ?Request $request = null): int|string|null
    {
        return static::enabledFor($module) ? static::currentTeamKey($request) : null;
    }

    /**
     * The active team's ROUTE key — the `{current_team}` segment Kinetix's own
     * endpoints are called under: the request's segment (a bound model's route
     * key), else the user's `currentTeam` route key. Null when teams are off or
     * no team resolves.
     *
     * A URL value, not a scoping key — scope data with {@see keyFor()}. It
     * performs no membership check, and no query on a team-routed request.
     */
    public static function currentRouteKey(?Request $request = null): int|string|null
    {
        if (! config('kinetix.teams', false)) {
            return null;
        }

        $request ??= request();

        // `{team}` is accepted too (the Billing routes use that name). The
        // param may be a bound MODEL, so it is never interpolated directly.
        $team = $request->route('current_team') ?? $request->route('team');

        if ($team instanceof Model) {
            $team = $team->getRouteKey();
        }

        if ($team === null || $team === '') {
            $user = $request->user() ?? auth()->user();
            $team = $user?->currentTeam?->getRouteKey();
        }

        return $team === '' ? null : $team;
    }

    /**
     * Whether the user is a member of the given team, through the configured
     * teams relation. Used where the team comes from a record rather than
     * from the URL (a link opened later, from anywhere).
     */
    public static function userBelongsTo(?Model $user, int|string $teamKey): bool
    {
        if ($user === null) {
            return false;
        }

        $relationName = (string) config('kinetix.team_switcher.teams_relation', 'teams');

        if (method_exists($user, $relationName)) {
            $relation = $user->{$relationName}();

            if ($relation instanceof Relation) {
                return $relation->getQuery()
                    ->where($relation->getRelated()->getQualifiedKeyName(), $teamKey)
                    ->exists();
            }
        }

        // No teams relation to check against: only the team the user is on.
        return (string) $user->getAttribute('current_team_id') === (string) $teamKey;
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

        // `{current_team}` is the canonical segment; `{team}` is accepted too
        // because the Billing routes (and hosts that predate the rename) use
        // that name — without this a Kinetix component rendered inside such a
        // page would silently fall back to the user's `currentTeam`.
        $param = $request->route('current_team') ?? $request->route('team');

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

    /**
     * Where to send someone who just created a team: what
     * `kinetix.team_switcher.redirect_after_create` says (a route name or a
     * callable array), else the app's own `$default`.
     *
     *     return redirect(KinetixTeams::redirectAfterCreate($team, route('teams.edit', $team)));
     */
    public static function redirectAfterCreate(Model $team, string $default): string
    {
        return app(TeamSwitcherManager::class)->redirectAfterCreate($team, $default);
    }
}
