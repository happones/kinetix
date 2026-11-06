<?php

declare(strict_types=1);

namespace Happones\Kinetix\Http\Middleware;

use Closure;
use Happones\Kinetix\Support\KinetixSharedProps;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After a successful write to a Kinetix module whose state a kept shared prop
 * shows (tours, roles, members, billing, …), have the browser fetch those
 * props again on its next visit ({@see KinetixSharedProps::refresh()}).
 *
 * A redirect counts as success: it is how most writes answer. Reads, failed
 * writes and requests without a session change nothing.
 *
 * Alias: `kinetix.shared-props`. Kinetix adds it to the routes of those
 * modules itself.
 */
class RefreshKinetixSharedProps
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethodSafe() || $response->getStatusCode() >= 400) {
            return $response;
        }

        KinetixSharedProps::refresh($request);

        return $response;
    }
}
