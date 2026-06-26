<?php

declare(strict_types=1);

namespace Happones\Kinetix\Wizards\Middleware;

use Closure;
use Happones\Kinetix\Wizards\WizardManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate routes behind a wizard: until the authenticated user has completed the
 * wizard `slug`, they are redirected to the route configured in
 * `kinetix.wizards.gates.<slug>`. Apply as `kinetix.wizard:account-setup`.
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

        if ($user === null) {
            return $next($request);
        }

        if ($this->manager->hasCompleted($user, $slug)) {
            return $next($request);
        }

        $redirectRoute = config("kinetix.wizards.gates.{$slug}");

        if (! is_string($redirectRoute) || $redirectRoute === '') {
            return $next($request);
        }

        // Avoid a redirect loop when already on the wizard page.
        if ($request->routeIs($redirectRoute)) {
            return $next($request);
        }

        return redirect()->route($redirectRoute);
    }
}
