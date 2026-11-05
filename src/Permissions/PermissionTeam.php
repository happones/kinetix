<?php

declare(strict_types=1);

namespace Happones\Kinetix\Permissions;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\PermissionRegistrar;

/**
 * Runs code inside one team's spatie permission context.
 *
 * With spatie's `permission.teams` on, every role and permission check reads
 * the registrar's team id. Code that judges or writes roles for a team other
 * than the request's one (a console scheduler, the billed team, a member
 * provision) pins that team for the callback, then restores the previous id,
 * even when the callback throws. A user whose roles and permissions are
 * already loaded has them dropped on the way in and on the way out, so
 * neither team's leak into the other.
 *
 * @internal
 */
final class PermissionTeam
{
    /**
     * Whether spatie scopes roles by team (installed, `permission.teams` on).
     */
    public static function active(): bool
    {
        return class_exists(PermissionRegistrar::class) && (bool) config('permission.teams', false);
    }

    /**
     * Run `$callback` with spatie's team id set to `$teamId`.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn $callback
     * @return TReturn
     */
    public static function run(int|string|null $teamId, callable $callback, ?Model $user = null): mixed
    {
        if (! self::active()) {
            return $callback();
        }

        $registrar = app(PermissionRegistrar::class);
        $previous  = $registrar->getPermissionsTeamId();

        if (self::same($previous, $teamId)) {
            return $callback();
        }

        $registrar->setPermissionsTeamId($teamId);
        self::forgetLoaded($user);

        try {
            return $callback();
        } finally {
            $registrar->setPermissionsTeamId($previous);
            self::forgetLoaded($user);
        }
    }

    private static function same(int|string|null $current, int|string|null $teamId): bool
    {
        if ($current === null || $teamId === null) {
            return $current === $teamId;
        }

        return (string) $current === (string) $teamId;
    }

    private static function forgetLoaded(?Model $user): void
    {
        $user?->unsetRelation('roles')->unsetRelation('permissions');
    }
}
