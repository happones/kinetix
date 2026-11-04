<?php

declare(strict_types=1);

namespace Happones\Kinetix\Permissions;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Seeds the classic RBAC preset on top of the feature-scoped registry:
 *
 * - `super-admin` — bypasses every gate (see Gate::before); needs no permissions.
 * - `admin`       — every declared permission except platform-level ones.
 * - `editor`      — the same, minus destructive abilities (delete/forceDelete).
 * - `viewer`      — read-only abilities (viewAny/view).
 *
 * Platform-level permissions ({@see Feature::platform()}, e.g.
 * `users.impersonate`) cross the tenant boundary, so no preset carries them:
 * these roles are global and handed out per team. Grant them to platform staff
 * deliberately, as a super-admin.
 *
 * Run it after `kinetix:permissions:sync`, or publish/extend it for your own roles.
 */
class KinetixRolesSeeder extends Seeder
{
    public function run(): void
    {
        $roleClass       = config('permission.models.role', Role::class);
        $permissionClass = config('permission.models.permission', Permission::class);
        $guard           = (string) config('kinetix.permissions.guard', 'web');

        $registry = app(PermissionRegistry::class);
        $all      = $registry->allPermissions();

        foreach ($all as $name) {
            $permissionClass::findOrCreate($name, $guard);
        }

        $all = array_values(array_diff($all, $registry->platformPermissions()));

        $roleClass::findOrCreate((string) config('kinetix.permissions.super_admin_role', 'super-admin'), $guard);

        $roleClass::findOrCreate('admin', $guard)->syncPermissions($all);

        $roleClass::findOrCreate('editor', $guard)->syncPermissions(array_values(array_filter(
            $all,
            static fn (string $p): bool => ! str_ends_with($p, '.delete') && ! str_ends_with($p, '.forceDelete'),
        )));

        $roleClass::findOrCreate('viewer', $guard)->syncPermissions(array_values(array_filter(
            $all,
            static fn (string $p): bool => str_ends_with($p, '.viewAny') || str_ends_with($p, '.view'),
        )));
    }
}
