<?php

declare(strict_types=1);

namespace Happones\Kinetix\Permissions;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\PermissionRegistrar;

/**
 * Resolves whether a user is the platform super-admin, honoring a **teamless**
 * assignment when spatie team scoping is active (a NULL-team super-admin keeps
 * the bypass inside every team). Shared by the `Gate::before` bypass, the
 * `kinetix_permissions` Inertia prop, and the role-management controller so all
 * three agree on the same rule.
 */
class SuperAdmin
{
    public static function role(): string
    {
        return (string) config('kinetix.permissions.super_admin_role', 'super-admin');
    }

    /**
     * Whether the user holds the super-admin role in the current team context
     * or as a global (teamless) assignment.
     */
    public static function check(mixed $user): bool
    {
        $role = static::role();

        if ($role === '' || $user === null || ! method_exists($user, 'hasRole')) {
            return false;
        }

        if ($user->hasRole($role)) {
            return true;
        }

        // With spatie teams on, the check above was scoped to the current team;
        // re-check with a NULL team id to honor a teamless assignment.
        if (! $user instanceof Model
            || ! config('permission.teams', false)
            || ! class_exists(PermissionRegistrar::class)) {
            return false;
        }

        $registrar = app(PermissionRegistrar::class);
        $current   = $registrar->getPermissionsTeamId();

        if ($current === null) {
            return false; // Already teamless — the first check covered it.
        }

        try {
            $registrar->setPermissionsTeamId(null);
            $user->unsetRelation('roles');

            return $user->hasRole($role);
        } finally {
            $registrar->setPermissionsTeamId($current);
            $user->unsetRelation('roles');
        }
    }
}
