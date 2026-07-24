<?php

declare(strict_types=1);

namespace Happones\Kinetix\Permissions\Middleware;

use Closure;
use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bridges spatie's team-scoped permissions to the ACTIVE team of the request.
 *
 * When `kinetix.permissions.teams` is on, spatie scopes roles/permissions by a
 * "team id" it reads from the registrar. The id is resolved through
 * {@see KinetixTeams::currentTeamKey()} — the same canonical resolver the rest
 * of Kinetix uses: the `{current_team}` route segment translated to a primary
 * key via the user's teams relation (which doubles as a membership check — a
 * segment the user doesn't belong to 404s), falling back to the user's
 * `currentTeam` when the route carries no segment. Evaluating permissions
 * against the URL's team (not just the user's sticky `current_team_id` column)
 * keeps the authorization context consistent with the data being served.
 */
class SetPermissionsTeam
{
    public function __construct(protected PermissionRegistrar $registrar) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (KinetixTeams::enabledFor('permissions')) {
            $this->registrar->setPermissionsTeamId(KinetixTeams::currentTeamKey($request));
        }

        return $next($request);
    }
}
