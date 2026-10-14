<?php

declare(strict_types=1);

namespace Happones\Kinetix\Membership;

use Happones\Kinetix\Support\ConfigCallback;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\PermissionRegistrar;

/**
 * Static entry point for provisioned members, outside the members screen: an
 * admin panel, a console command, a job.
 *
 *     KinetixMembership::revoke($provision);
 */
class KinetixMembership
{
    /**
     * Revoke a provisioned member: their role in the provision's team goes,
     * the host's `membership.detach_member` callback runs, and the provision
     * is kept, revoked, for the audit trail. Revoking twice changes nothing.
     */
    public static function revoke(MemberProvision $provision): void
    {
        if ($provision->status === MemberProvisionStatus::Revoked) {
            return;
        }

        if ($provision->user_id !== null) {
            $user = static::user($provision->user_id);

            static::withTeam($provision->team_id, function () use ($user, $provision): void {
                if ($user !== null && method_exists($user, 'removeRole')) {
                    $user->removeRole($provision->role);
                }
            });

            $detach = ConfigCallback::resolve(config('kinetix.membership.detach_member'));

            if ($detach !== null) {
                $detach($user, $provision);
            }
        }

        $provision->update(['status' => MemberProvisionStatus::Revoked]);
    }

    /**
     * The member's user record, from `kinetix.membership.user_model`.
     */
    public static function user(int|string|null $id): ?Model
    {
        if ($id === null) {
            return null;
        }

        /** @var class-string<Model> $model */
        $model = (string) config('kinetix.membership.user_model', 'App\\Models\\User');

        return $model::find($id);
    }

    /**
     * Run a callback with spatie's team id pinned to the provision's team, so
     * role (un)assignment lands in the right tenant scope, then restore it.
     *
     * Keyed off SPATIE's `permission.teams` — that flag alone decides whether
     * pivot rows carry a team id. Keying off Kinetix's membership flag here
     * mis-scoped writes when `membership.teams` was off but `permission.teams`
     * on: the public activation route has no team middleware, so the registrar
     * value would be stale/undefined and the role landed in the wrong tenant.
     */
    public static function withTeam(int|string|null $teamId, callable $callback): void
    {
        if (! class_exists(PermissionRegistrar::class) || ! (bool) config('permission.teams', false)) {
            $callback();

            return;
        }

        $registrar = app(PermissionRegistrar::class);
        $previous  = $registrar->getPermissionsTeamId();

        $registrar->setPermissionsTeamId($teamId);

        try {
            $callback();
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }
}
