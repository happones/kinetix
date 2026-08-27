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
 * the optional `kinetix.permissions.owner_bypass` gate bypass.
 *
 * In a teams app "the owner can do everything" is the most common rule, and it
 * is *not* expressible as a role: ownership lives in the host's own team schema
 * (`teams.user_id`, a pivot flag, …), not in `model_has_roles`. Rather than
 * making every host hand-write a `Gate::before`, this turns it into config:
 *
 *  - `true` — use the host's `$user->ownsTeam($team)` (Jetstream / the
 *    vue-starter-kit's `HasTeams` convention);
 *  - a `Closure` or the class-string of an invokable class —
 *    `fn ($user, $team) => bool`, for a bespoke ownership rule;
 *  - `null` (default) — off, no bypass is registered.
 *
 * The active team is the one the permission layer is scoped to (the
 * `{current_team}` segment via `SetPermissionsTeam`), falling back to the
 * user's `currentTeam`. The verdict is memoized per (user object × team)
 * through {@see Memo} — `Gate::before` fires on every authorization check, and
 * resolving the team can cost a query — exactly like {@see SuperAdmin}.
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
        $value = config('kinetix.permissions.owner_bypass');

        return $value === true || ConfigCallback::resolve($value) !== null;
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

    protected static function teamKey(): string
    {
        if (! class_exists(PermissionRegistrar::class)) {
            return 'none';
        }

        return (string) (app(PermissionRegistrar::class)->getPermissionsTeamId() ?? 'none');
    }

    protected static function resolve(mixed $user): bool
    {
        $value    = config('kinetix.permissions.owner_bypass');
        $callback = ConfigCallback::resolve($value);

        if ($callback !== null) {
            return $callback($user, static::activeTeam($user)) === true;
        }

        if ($value !== true || ! method_exists($user, 'ownsTeam')) {
            return false;
        }

        $team = static::activeTeam($user);

        return $team !== null && (bool) $user->ownsTeam($team);
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
