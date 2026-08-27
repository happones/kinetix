<?php

declare(strict_types=1);

namespace Happones\Kinetix\Permissions;

use Happones\Kinetix\Support\Memo;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\PermissionRegistrar;

/**
 * Resolves whether a user is the platform super-admin, honoring a **teamless**
 * assignment when spatie team scoping is active (a NULL-team super-admin keeps
 * the bypass inside every team). Shared by the `Gate::before` bypass, the
 * `kinetix_permissions` Inertia prop, and the role-management controller so all
 * three agree on the same rule.
 *
 * The result is memoized per (user object × permissions-team-id) through
 * {@see Memo} so the `Gate::before` bypass — which fires on every
 * authorization check — doesn't repeatedly reload the user's roles (the
 * teamless re-check reloads them). The team id is the inner key, so a
 * team-scoped super-admin is still re-evaluated per team.
 */
class SuperAdmin
{
    /** The {@see Memo} store these verdicts live in. */
    public const MEMO = 'permissions.super-admin';

    public static function role(): string
    {
        return (string) config('kinetix.permissions.super_admin_role', 'super-admin');
    }

    /**
     * Role names that must not be created, renamed to, edited or deleted from
     * the management UI — and that are legitimately global (teamless) under team
     * scoping. Defaults to just the super-admin role.
     *
     * @return array<int, string>
     */
    public static function protectedRoles(): array
    {
        $configured = config('kinetix.permissions.protected_roles');

        return $configured !== null
            ? array_values(array_map('strval', (array) $configured))
            : [static::role()];
    }

    /**
     * Whether the user holds the super-admin role in the current team context
     * or as a global (teamless) assignment. Memoized per user × team.
     */
    public static function check(mixed $user): bool
    {
        if (! is_object($user)) {
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
     * Clear the memo. Mainly for tests / long-running processes that mutate a
     * user's super-admin role within the same process.
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
        $role = static::role();

        if ($role === '' || ! method_exists($user, 'hasRole')) {
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
