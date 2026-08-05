<?php

declare(strict_types=1);

namespace Happones\Kinetix\Permissions;

use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;

/**
 * Config-cache-safe resolver for `kinetix.membership.assignable_roles`: the
 * roles actually VISIBLE in the provision's team — the team's own roles PLUS
 * global (team-NULL) ones — minus the protected roles (the super-admin role
 * by default). This is the same visibility rule the Roles UI uses, so the
 * member-invite picker and the role manager can never disagree about which
 * roles exist.
 *
 *     // config/kinetix.php
 *     'membership' => [
 *         'assignable_roles' => \Happones\Kinetix\Permissions\AssignableRoles::class,
 *     ],
 *
 * Note the query deliberately includes `whereNull(team_id)` — a plain
 * `where('team_id', $teamId)` silently drops every global role, including the
 * `admin`/`editor`/`viewer` presets `KinetixRolesSeeder` creates.
 */
class AssignableRoles
{
    /**
     * @return array<int, string>
     */
    public function __invoke(int|string|null $teamId = null): array
    {
        return static::names($teamId);
    }

    /**
     * Role names assignable in the given team: team-scoped + global, minus
     * protected roles and any extra `$except` names the host wants withheld
     * (e.g. `['admin']` to keep admin promotion out of the invite flow).
     *
     * @param  array<int, string> $except
     * @return array<int, string>
     */
    public static function names(int|string|null $teamId = null, array $except = []): array
    {
        return static::query($teamId)
            ->whereNotIn('name', array_merge(SuperAdmin::protectedRoles(), $except))
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /**
     * The visibility scope itself (guard + team-or-global), for hosts that
     * want to build their own allow-list on top of it.
     *
     * @return Builder<Role>
     */
    public static function query(int|string|null $teamId = null): Builder
    {
        /** @var class-string<Role> $model */
        $model = config('permission.models.role', Role::class);

        $query = $model::query()
            ->where('guard_name', (string) config('kinetix.permissions.guard', 'web'));

        if ((bool) config('permission.teams', false)) {
            $teamsKey = (string) (config('permission.column_names.team_foreign_key') ?? 'team_id');

            $query->where(function (Builder $inner) use ($teamsKey, $teamId): void {
                $inner->whereNull($teamsKey);

                if ($teamId !== null) {
                    $inner->orWhere($teamsKey, $teamId);
                }
            });
        }

        return $query;
    }
}
