<?php

declare(strict_types=1);

namespace Happones\Kinetix\Entitlements\Middleware;

use Closure;
use Happones\Kinetix\Entitlements\EntitlementRegistry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route on a declared entitlement (alias `kinetix.entitled`):
 *
 *     Route::post('projects', ...)->middleware('kinetix.entitled:projects.create');
 *
 * One middleware replaces the `kinetix.feature:… + kinetix.plan:… + can:…`
 * stack, and — unlike stacking them — the response matches WHICH layer refused:
 * a flag denial 404s, a plan or usage-limit denial takes the upsell redirect,
 * a permission denial 403s.
 *
 * Several entitlements can be required at once; ALL must allow, and the first
 * denial decides the response:
 *
 *     ->middleware('kinetix.entitled:projects.create,billing.view')
 */
class EnsureEntitled
{
    public function __construct(protected EntitlementRegistry $entitlements) {}

    public function handle(Request $request, Closure $next, string ...$names): Response
    {
        foreach ($names as $name) {
            $denial = $this->entitlements->check($name)->enforce();

            if ($denial !== null) {
                return $denial;
            }
        }

        return $next($request);
    }
}
