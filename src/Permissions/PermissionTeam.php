<?php

declare(strict_types=1);

namespace Happones\Kinetix\Permissions;

use Happones\Kinetix\Support\HostKeys;
use Happones\Kinetix\Support\KinetixTeams;
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

    /** @var array{0: Model|null, 1: int|string}|null */
    private static ?array $pinned = null;

    /**
     * Run `$callback` as one team's work even where nothing in the request
     * says which team that is (a queued job): the current team
     * ({@see KinetixTeams}) and the owner check
     * ({@see TeamOwner}) answer with it, instead of the user's own
     * `currentTeam`, whether or not spatie scopes roles by team. Restored
     * after, even when the callback throws.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn $callback
     * @return TReturn
     */
    public static function pin(int|string|null $teamId, callable $callback, ?Model $user = null): mixed
    {
        $previous     = self::$pinned;
        self::$pinned = $teamId === null ? null : [null, $teamId];

        try {
            return self::run($teamId, $callback, $user);
        } finally {
            self::$pinned = $previous;
        }
    }

    /**
     * The key a {@see pin()} is running for, without loading the team.
     */
    public static function pinnedKey(): int|string|null
    {
        return self::$pinned[1] ?? null;
    }

    /**
     * The team and key a {@see pin()} is running for, null outside one.
     *
     * @return array{0: Model|null, 1: int|string}|null
     */
    public static function pinned(): ?array
    {
        if (self::$pinned === null) {
            return null;
        }

        if (self::$pinned[0] === null) {
            $model = HostKeys::teamModel();
            $team  = $model === null ? null : $model::query()->find(self::$pinned[1]);

            self::$pinned[0] = $team instanceof Model ? $team : null;
        }

        return self::$pinned;
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
