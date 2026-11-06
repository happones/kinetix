<?php

declare(strict_types=1);

namespace Happones\Kinetix\Membership;

use Happones\Kinetix\Membership\Events\MemberRevoked;
use Happones\Kinetix\Permissions\PermissionTeam;
use Happones\Kinetix\Support\ConfigCallback;
use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
     *
     * All of it is one transaction: a callback that throws leaves the member
     * as they were. {@see MemberRevoked} fires after commit, once, with the
     * `$actor` (null when done from code).
     */
    public static function revoke(MemberProvision $provision, ?Authenticatable $actor = null): void
    {
        if ($provision->status === MemberProvisionStatus::Revoked) {
            return;
        }

        $revoked = DB::transaction(static function () use ($provision): bool {
            // Re-read under a row lock: a concurrent revoke that got here first
            // leaves nothing for this one to do (and no second event).
            $locked = MemberProvision::query()->whereKey($provision->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->status === MemberProvisionStatus::Revoked) {
                return false;
            }

            $provision->setRawAttributes($locked->getAttributes(), true);

            if ($provision->user_id !== null) {
                $user = static::user($provision->user_id);

                if ($user !== null) {
                    static::syncRole($user, $provision, from: $provision->role, to: null);
                }

                $detach = ConfigCallback::resolve(config('kinetix.membership.detach_member'));

                if ($detach !== null) {
                    $detach($user, $provision);

                    // The callback changed team membership: a team resolved
                    // earlier in this request must be checked again.
                    KinetixTeams::forgetCurrentTeam();
                }
            }

            $provision->update(['status' => MemberProvisionStatus::Revoked]);

            return true;
        });

        if (! $revoked) {
            return;
        }

        MemberRevoked::dispatch($provision, $actor);
    }

    /**
     * The host's custom member fields, or null when `membership.fields` is
     * not set. A class that is missing or is not a {@see MemberFields} throws:
     * dropping the fields silently would hide a misconfiguration.
     */
    public static function fields(): ?MemberFields
    {
        $class = config('kinetix.membership.fields');

        if ($class === null || $class === '') {
            return null;
        }

        if (! is_string($class) || ! is_subclass_of($class, MemberFields::class)) {
            throw new InvalidArgumentException(
                'kinetix.membership.fields must be the class-string of a class extending '.MemberFields::class.'.',
            );
        }

        return app($class);
    }

    /**
     * Move a member from one role to another (either side may be null: null
     * `from` grants, null `to` removes).
     *
     * Roles don't have to be Roles-screen roles. When the app keeps its own
     * notion of a member role (a column, a pivot flag, another package),
     * `membership.assign_role` takes over — `[Class::class, 'method']`
     * receiving `($user, ?string $to, ?string $from, MemberProvision)` — and
     * Kinetix never touches spatie. Without it the role is a spatie role,
     * (un)assigned in the provision's team.
     */
    public static function syncRole(Model $user, MemberProvision $provision, ?string $from, ?string $to): void
    {
        $assign = ConfigCallback::resolve(config('kinetix.membership.assign_role'));

        if ($assign !== null) {
            $assign($user, $to, $from, $provision);

            return;
        }

        static::withTeam($provision->team_id, static function () use ($user, $from, $to): void {
            if ($from !== null && $from !== $to && method_exists($user, 'removeRole')) {
                $user->removeRole($from);
            }

            if ($to !== null && method_exists($user, 'assignRole')) {
                $user->assignRole($to);
            }
        });
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
        PermissionTeam::run($teamId, $callback);
    }
}
