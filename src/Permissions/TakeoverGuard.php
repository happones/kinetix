<?php

declare(strict_types=1);

namespace Happones\Kinetix\Permissions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Throwable;

/**
 * Whether one user may take over another's session or credentials
 * (impersonation, a temporary password issued from Members).
 *
 * Becoming someone is inheriting everything they hold, in every team they
 * belong to, so the actor must already hold at least as much there:
 *
 *  1. a super-admin target needs a super-admin actor;
 *  2. a super-admin actor may take over anyone else;
 *  3. under teams, every team of the target must also be a team of the
 *     actor — a grant held in one team never reaches a user of another;
 *  4. in each of the target's teams, a protected permission the target holds
 *     must be held by the actor too, and a target who owns the team needs an
 *     actor who owns it as well. Owning the team counts as holding a
 *     protected permission only when the owner bypass grants it there.
 *
 * Team membership follows `kinetix.team_switcher.teams_relation`, falling
 * back to `current_team_id`; ownership follows {@see TeamOwner::owns()}; each
 * team's permissions are read inside that team ({@see PermissionTeam::run()}).
 *
 * @internal
 */
final class TakeoverGuard
{
    /**
     * @param array<int, mixed> $protectedPermissions
     */
    public static function allows(Authenticatable $actor, Authenticatable $target, array $protectedPermissions): bool
    {
        if (SuperAdmin::check($target)) {
            return SuperAdmin::check($actor);
        }

        if (SuperAdmin::check($actor)) {
            return true;
        }

        $permissions = array_values(array_filter(array_map(
            static fn (mixed $permission): string => is_string($permission) ? $permission : '',
            $protectedPermissions,
        )));

        if (! config('kinetix.teams', false)) {
            return self::holdsProtected($actor, $target, $permissions);
        }

        if (! $actor instanceof Model || ! $target instanceof Model) {
            return false;
        }

        $targetTeams = self::teamsOf($target);

        // A teamless target is judged where the request stands.
        if ($targetTeams === []) {
            return self::holdsProtected($actor, $target, $permissions);
        }

        $actorTeams = array_map(static fn (int|string $key): string => (string) $key, array_keys(self::teamsOf($actor)));

        foreach ($targetTeams as $teamKey => $team) {
            if (! in_array((string) $teamKey, $actorTeams, true)) {
                return false;
            }

            if (! self::allowsInTeam($actor, $target, $teamKey, $team, $permissions)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Rule 4 inside one team: ownership, then the protected permissions. An
     * actor who owns the team holds a protected permission there only when
     * the owner bypass grants it ({@see TeamOwner::grants()}: on, inside
     * `only`, outside `except`, not platform-level).
     *
     * @param list<string> $permissions
     */
    private static function allowsInTeam(Model $actor, Model $target, int|string $teamKey, ?Model $team, array $permissions): bool
    {
        $actorOwns = $team !== null && TeamOwner::owns($actor, $team);

        if ($team !== null && ! $actorOwns && TeamOwner::owns($target, $team)) {
            return false;
        }

        if ($actorOwns && TeamOwner::enabled()) {
            $permissions = array_values(array_diff($permissions, TeamOwner::filter($permissions)));
        }

        if ($permissions === []) {
            return true;
        }

        return PermissionTeam::run($teamKey, static function () use ($actor, $target, $permissions): bool {
            $actor->unsetRelation('roles')->unsetRelation('permissions');

            try {
                return self::holdsProtected($actor, $target, $permissions);
            } finally {
                $actor->unsetRelation('roles')->unsetRelation('permissions');
            }
        }, $target);
    }

    /**
     * Whether the actor holds every protected permission the target holds,
     * in the permission team currently in effect.
     *
     * @param list<string> $permissions
     */
    private static function holdsProtected(object $actor, object $target, array $permissions): bool
    {
        if (! method_exists($target, 'hasPermissionTo')) {
            return true;
        }

        foreach ($permissions as $permission) {
            // A permission the host never registered can't be held by anyone;
            // hasPermissionTo() throws on unknown names, so treat it as absent.
            try {
                if (! $target->hasPermissionTo($permission)) {
                    continue;
                }

                if (! method_exists($actor, 'hasPermissionTo') || ! $actor->hasPermissionTo($permission)) {
                    return false;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return true;
    }

    /**
     * The user's teams, keyed by team key: through the teams relation, else
     * the team the user is on (`current_team_id`).
     *
     * @return array<int|string, Model|null>
     */
    private static function teamsOf(Model $user): array
    {
        $relationName = (string) config('kinetix.team_switcher.teams_relation', 'teams');

        if (method_exists($user, $relationName)) {
            $relation = $user->{$relationName}();

            if ($relation instanceof Relation) {
                $teams = [];

                foreach ($relation->get() as $team) {
                    $teams[$team->getKey()] = $team;
                }

                return $teams;
            }
        }

        $current = $user->getAttribute('current_team_id');

        if (! is_int($current) && ! is_string($current)) {
            return [];
        }

        $team = method_exists($user, 'currentTeam') ? $user->getRelationValue('currentTeam') : null;

        return [$current => $team instanceof Model ? $team : null];
    }
}
