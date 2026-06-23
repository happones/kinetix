<?php

declare(strict_types=1);

namespace Happones\Kinetix\Billing\Middleware;

use Closure;
use Happones\Kinetix\Billing\BillingManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route on a plan feature: `->middleware('plan.feature:capabilities.api')`.
 *
 * Resolves the billable through {@see BillingManager}
 * and aborts 403 when the current plan does not grant the dot-path feature.
 */
class PlanFeatureMiddleware
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $billable = BillingManager::resolve()->billable();

        $allowed = method_exists($billable, 'canUseFeature')
            && $billable->canUseFeature($feature);

        if (! $allowed) {
            abort(403, (string) trans('kinetix.billing_feature_unavailable'));
        }

        return $next($request);
    }
}
