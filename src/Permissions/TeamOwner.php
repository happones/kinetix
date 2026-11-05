<?php

declare(strict_types=1);

namespace Happones\Kinetix\Permissions;

use Happones\Kinetix\Support\ConfigCallback;
use Happones\Kinetix\Support\Memo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\Permission\PermissionRegistrar;

/**
 * Resolves whether a user owns the team the current request is scoped to, for
 * the optional `kinetix.permissions.owner_bypass` gate bypass, and which
 * registered abilities that bypass covers.
 *
 * In a teams app "the owner can do everything" is the most common rule, and it
 * is *not* expressible as a role: ownership lives in the host's own team schema
 * (`teams.user_id`, a pivot flag, …), not in `model_has_roles`. Rather than
 * making every host hand-write a `Gate::before`, this turns it into config.
 * The option takes either a bare ownership rule or a structured array:
 *
 *  - `true` — use the host's `$user->ownsTeam($team)` (Jetstream / the
 *    vue-starter-kit's `HasTeams` convention);
 *  - a callable array `[Class::class, 'method']` or the class-string of an
 *    invokable class — receives `($user, $team)` and returns a bool, for a
 *    bespoke ownership rule;
 *  - `null` / `[]` (default) — off, no bypass is registered;
 *  - `['using' => <one of the above>, 'only' => [...] | null, 'except' => [...]]`
 *    — the same rule, scoped. `only` (null = every registered ability) and
 *    `except` take patterns that follow the registry's structure: a feature
 *    name (`'settings'`, same as `'settings.*'`) or an exact key
 *    (`'users.impersonate'`). Anything else (`'*'`, `'u*'`) matches nothing.
 *
 * Granted = (only ?? every registered ability) − except − platform abilities
 * ({@see Feature::platform()}) − unregistered keys. Platform abilities cross
 * the tenant boundary, so owning one team never grants them. Malformed config
 * fails closed (off / matches nothing).
 *
 * The active team is the one the permission layer is scoped to (the
 * `{current_team}` segment via `SetPermissionsTeam`), falling back to the
 * user's `currentTeam`. The verdict is memoized per (user object × team)
 * through {@see Memo} — `Gate::before` fires on every authorization check, and
 * resolving the team can cost a query — exactly like {@see SuperAdmin}. The
 * scope itself ({@see grants()}) never resolves the user or the team.
 */
class TeamOwner
{
    /** The {@see Memo} store these verdicts live in. */
    public const MEMO = 'permissions.team-owner';

    /**
     * Whether a bypass is configured at all (checked once, at boot).
     */
    public static function enabled(): bool
    {
        $using = static::options()['using'];

        return $using === true || ConfigCallback::resolve($using) !== null;
    }

    /**
     * Whether the bypass covers a permission key: registered, not
     * platform-level, inside `only` and outside `except`. A pure scope
     * predicate — it never resolves the user or the team, so it is cheap
     * enough to run before {@see check()} on every Gate call.
     */
    public static function grants(string $ability): bool
    {
        return static::filter([$ability]) !== [];
    }

    /**
     * The subset of `$abilities` the bypass covers ({@see grants()}), reading
     * the config once for the whole batch. Order is preserved.
     *
     * @param  array<int, string> $abilities
     * @return array<int, string>
     */
    public static function filter(array $abilities): array
    {
        $registry = app(PermissionRegistry::class);
        $options  = static::options();
        $granted  = [];

        foreach ($abilities as $ability) {
            $feature = $registry->featureOf($ability);

            if ($feature === null || $registry->isPlatform($ability)) {
                continue;
            }

            if ($options['only'] !== null && ! static::matches($ability, $feature, $options['only'])) {
                continue;
            }

            if (static::matches($ability, $feature, $options['except'])) {
                continue;
            }

            $granted[] = $ability;
        }

        return $granted;
    }

    /**
     * Whether the user owns the team the request is scoped to. Memoized per
     * user × team.
     */
    public static function check(mixed $user): bool
    {
        if (! is_object($user) || ! static::enabled()) {
            return false;
        }

        return (bool) Memo::remember(
            self::MEMO,
            $user,
            static::teamKey(),
            static fn (): bool => static::resolve($user),
        );
    }

    /**
     * Clear the memo. For tests / long-running processes where ownership
     * changes within the same process.
     */
    public static function flush(): void
    {
        Memo::flush(self::MEMO);
    }

    /**
     * The configured scope, normalized: `only` (null = every registered
     * ability) and `except` patterns. For diagnostics (`kinetix:doctor`); the
     * Gate and the prop go through {@see grants()} / {@see filter()}.
     *
     * @internal Diagnostics only; not a stable extension point.
     *
     * @return array{only: list<string>|null, except: list<string>}
     */
    public static function scope(): array
    {
        $options = static::options();

        return ['only' => $options['only'], 'except' => $options['except']];
    }

    /**
     * The `only` / `except` patterns that match no registered feature or key —
     * almost always a typo, and a typo in `except` silently over-grants.
     *
     * @internal Diagnostics only; not a stable extension point.
     *
     * @return list<string>
     */
    public static function unmatchedPatterns(): array
    {
        $registry  = app(PermissionRegistry::class);
        $scope     = static::scope();
        $keys      = $registry->allPermissions();
        $unmatched = [];

        foreach ([...($scope['only'] ?? []), ...$scope['except']] as $pattern) {
            $matched = false;

            foreach ($keys as $key) {
                if (static::matches($key, (string) $registry->featureOf($key), [$pattern])) {
                    $matched = true;

                    break;
                }
            }

            if (! $matched) {
                $unmatched[] = $pattern;
            }
        }

        return array_values(array_unique($unmatched));
    }

    /**
     * The normalized `owner_bypass` option. A string-keyed array is the
     * structured form; anything else is the legacy bare rule, unscoped.
     *
     * @return array{using: mixed, only: list<string>|null, except: list<string>}
     */
    protected static function options(): array
    {
        $value = config('kinetix.permissions.owner_bypass');

        if (! is_array($value) || array_is_list($value)) {
            return ['using' => $value, 'only' => null, 'except' => []];
        }

        $only = $value['only'] ?? null;

        return [
            'using'  => $value['using'] ?? null,
            'only'   => $only === null ? null : static::patterns($only),
            'except' => static::patterns($value['except'] ?? []),
        ];
    }

    /**
     * @return list<string>
     */
    protected static function patterns(mixed $value): array
    {
        $patterns = [];

        foreach ((array) $value as $pattern) {
            if (is_string($pattern) || is_int($pattern)) {
                $patterns[] = (string) $pattern;
            }
        }

        return $patterns;
    }

    /**
     * Whether a key matches any pattern: its feature name, `{feature}.*`, or
     * the exact key.
     *
     * @param array<int, string> $patterns
     */
    protected static function matches(string $ability, string $feature, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === $ability || $pattern === $feature || $pattern === $feature.'.*') {
                return true;
            }
        }

        return false;
    }

    protected static function teamKey(): string
    {
        if (! class_exists(PermissionRegistrar::class)) {
            return 'none';
        }

        return (string) (app(PermissionRegistrar::class)->getPermissionsTeamId() ?? 'none');
    }

    /**
     * Whether the user owns the given team: the `owner_bypass` ownership
     * callback when one is configured, else the host's `ownsTeam($team)`.
     * Independent of whether the bypass is on, so other guards (a takeover
     * of a team owner) can ask it too.
     */
    public static function owns(Model $user, Model $team): bool
    {
        $callback = ConfigCallback::resolve(static::options()['using']);

        if ($callback !== null) {
            return $callback($user, $team) === true;
        }

        return method_exists($user, 'ownsTeam') && (bool) $user->ownsTeam($team);
    }

    protected static function resolve(mixed $user): bool
    {
        $value    = static::options()['using'];
        $callback = ConfigCallback::resolve($value);

        if ($callback !== null) {
            return $callback($user, static::activeTeam($user)) === true;
        }

        if ($value !== true || ! $user instanceof Model) {
            return false;
        }

        $team = static::activeTeam($user);

        return $team !== null && static::owns($user, $team);
    }

    /**
     * The team the request is scoped to: the permission layer's team id (set
     * from the `{current_team}` segment) resolved to a model, else the user's
     * `currentTeam`.
     */
    protected static function activeTeam(mixed $user): ?Model
    {
        if (! $user instanceof Model) {
            return null;
        }

        $current = $user->currentTeam ?? null;
        $teamId  = static::teamKey();

        if ($teamId === 'none') {
            return $current instanceof Model ? $current : null;
        }

        if ($current instanceof Model && (string) $current->getKey() === $teamId) {
            return $current;
        }

        // Resolve through the user's teams relation, which doubles as a
        // membership check — a team the user doesn't belong to yields null.
        $relationName = (string) config('kinetix.team_switcher.teams_relation', 'teams');

        if (! method_exists($user, $relationName)) {
            return null;
        }

        $relation = $user->{$relationName}();

        if (! $relation instanceof Relation) {
            return null;
        }

        $related = $relation->getRelated();

        return $relation
            ->where($related->qualifyColumn($related->getKeyName()), $teamId)
            ->first();
    }
}
