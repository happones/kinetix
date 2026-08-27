<?php

declare(strict_types=1);

namespace Happones\Kinetix\Billing\Middleware;

use Closure;
use Happones\Kinetix\Billing\BillingManager;
use Happones\Kinetix\Billing\UpsellResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Gate a route on a plan CAPABILITY (the `features.capabilities.*` namespace):
 * `->middleware('kinetix.plan:api')`.
 *
 * Denied WEB requests redirect to the configured upgrade page
 * (`kinetix.billing.upgrade_url`) with a flash message — the upsell pattern —
 * falling back to a plain 403 when none is configured. JSON requests always
 * get the 403 with the message. Unresolvable billables (guests, misconfig)
 * are denied, never 500.
 */
class EnsurePlanCapability
{
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        if ($this->allows($capability)) {
            return $next($request);
        }

        return UpsellResponse::make((string) __('kinetix.billing_feature_unavailable'));
    }

    protected function allows(string $capability): bool
    {
        try {
            $billable = BillingManager::resolve()->billable();
        } catch (Throwable) {
            return false;
        }

        return method_exists($billable, 'planAllows')
            && $billable->planAllows($capability);
    }
}
