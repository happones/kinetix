<?php

declare(strict_types=1);

namespace Happones\Kinetix\Wizards\Middleware;

use Closure;
use Happones\Kinetix\Support\KinetixTeams;
use Happones\Kinetix\Wizards\WizardManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate routes behind a wizard: until the authenticated user (or, with
 * `scope: team`, anyone in their team) has completed the wizard `slug`, they
 * are redirected to the route its gate names (`kinetix.wizards.gates.<slug>`).
 * Apply as `kinetix.wizard:account-setup`.
 *
 * The page they were going to is remembered, so the wizard can send them
 * back with `redirect()->intended(...)`. A JSON request gets a 409 naming the
 * wizard instead of a redirect.
 *
 * No-ops (passes through) when the user is unauthenticated (let `auth` handle
 * it), when the slug has no configured redirect route, or when the request is
 * already targeting that route (so the wizard page itself is reachable).
 */
class EnsureWizardCompleted
{
    public function __construct(protected WizardManager $manager) {}

    public function handle(Request $request, Closure $next, string $slug): Response
    {
        $user = $request->user();

        if ($user === null || $this->manager->hasCompleted($user, $slug)) {
            return $next($request);
        }

        $redirectRoute = $this->manager->gate($slug)['route'];

        // No route to send them to, or already on it (no redirect loop).
        if ($redirectRoute === null || $request->routeIs($redirectRoute)) {
            return $next($request);
        }

        $url = route($redirectRoute, $this->teamParameter($redirectRoute));

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('kinetix.wizard_required'),
                'wizard'  => $slug,
                'url'     => $url,
            ], Response::HTTP_CONFLICT);
        }

        return redirect()->guest($url);
    }

    /**
     * The team segment for a wizard route under it (`{current_team}`/`{team}`),
     * from the request being served.
     *
     * @return array<string, int|string>
     */
    protected function teamParameter(string $routeName): array
    {
        $route = Route::getRoutes()->getByName($routeName);
        $team  = KinetixTeams::currentRouteKey();

        if ($route === null || $team === null) {
            return [];
        }

        foreach (['current_team', 'team'] as $parameter) {
            if (in_array($parameter, $route->parameterNames(), true)) {
                return [$parameter => $team];
            }
        }

        return [];
    }
}
