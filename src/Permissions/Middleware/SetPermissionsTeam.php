<?php

declare(strict_types=1);

namespace Happones\Kinetix\Permissions\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bridges spatie's team-scoped permissions to the starter-kit's current team.
 *
 * When `kinetix.permissions.teams` is on, spatie scopes roles/permissions by a
 * "team id" it reads from the registrar. The starter kit already tracks the
 * active team on the user (`currentTeam`), so this middleware copies that id
 * into spatie — Kinetix never touches the User's own `teams()` relation.
 */
class SetPermissionsTeam
{
    public function __construct(protected PermissionRegistrar $registrar) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (config('kinetix.permissions.teams', false)) {
            $user = $request->user();
            $team = $user?->currentTeam;

            $this->registrar->setPermissionsTeamId($team?->getKey());
        }

        return $next($request);
    }
}
