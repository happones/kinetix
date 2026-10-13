<?php

declare(strict_types=1);

namespace Happones\Kinetix\Http\Middleware;

use Closure;
use Happones\Kinetix\KinetixServiceProvider;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shares Kinetix's Inertia props (`kinetix_config`, `kinetix_permissions`,
 * `kinetix_notifications`, …) on every web request.
 *
 * They're shared once when the app boots too, but a long-lived process can
 * flush Inertia's shared data between requests — a browser-test server does
 * before every request, a worker that resets state may — and the next page
 * would render without them: no permission-gated menus, no route prefix.
 * Sharing again here costs a few closures, never a query.
 */
class ShareKinetixProps
{
    public function handle(Request $request, Closure $next): Response
    {
        KinetixServiceProvider::shareInertiaProps();

        return $next($request);
    }
}
