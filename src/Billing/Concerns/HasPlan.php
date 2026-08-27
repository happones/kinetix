<?php

declare(strict_types=1);

namespace Happones\Kinetix\Billing\Concerns;

use Happones\Kinetix\Billing\Plan;
use Happones\Kinetix\Billing\PlanCatalog;
use Happones\Kinetix\Support\Memo;

/**
 * Add to the Cashier Billable model (User/Team/…) to resolve its current Plan
 * and gate features. Cashier subscription methods are accessed defensively so
 * the trait is safe even before a subscription exists.
 *
 * Every helper here funnels through {@see currentPlan()}, which is resolved
 * **once per billable per request** and answered from the in-memory
 * {@see PlanCatalog} — so gating ten things on a page costs one plan
 * resolution, not ten queries. Override {@see resolveCurrentPlan()} (not
 * `currentPlan()`) to keep that memoization.
 */
trait HasPlan
{
    /**
     * The billable's current plan — its subscription's price mapped to a plan,
     * a generic-trial plan, or the free fallback. Null only when the catalog
     * has nothing to fall back to.
     *
     * Memoized per billable object for the rest of the request. Mutating the
     * subscription in a long-running process (a worker that upgrades a team,
     * a test that swaps plans) must call {@see forgetCurrentPlan()}.
     */
    public function currentPlan(): ?Plan
    {
        return Memo::remember(
            PlanCatalog::RESOLVED_MEMO,
            $this,
            $this->planSubscriptionType(),
            fn (): ?Plan => $this->resolveCurrentPlan(),
        );
    }

    /**
     * Drop the memoized plan for this billable, so the next `currentPlan()`
     * resolves again.
     */
    public function forgetCurrentPlan(): void
    {
        Memo::flush(PlanCatalog::RESOLVED_MEMO, $this);
    }

    /**
     * Resolve the billable's current plan from its active subscription's price,
     * or the free fallback when there is no matching paid subscription.
     *
     * Override this — rather than `currentPlan()` — for a bespoke resolution:
     * the memoization wrapper stays in place.
     */
    protected function resolveCurrentPlan(): ?Plan
    {
        $trialGeneric = (bool) config('kinetix.billing.trial_generic', false);

        if ($trialGeneric && method_exists($this, 'onGenericTrial') && $this->onGenericTrial()) {
            // A billable on a generic trial is pinned to its trial plan — and
            // to NOTHING when the configured slug matches no plan. Falling
            // through to the free plan here would silently hand a broken trial
            // setup the free plan's features instead of surfacing the misconfig.
            if (filled($this->trial_plan ?? null)) {
                return PlanCatalog::bySlug($this->trial_plan);
            }
        }

        if (method_exists($this, 'subscription')) {
            $subscription = $this->subscription($this->planSubscriptionType());

            if ($subscription !== null) {
                $plan = PlanCatalog::byPriceId($subscription->stripe_price ?? null);

                if ($plan !== null) {
                    return $plan;
                }
            }
        }

        return PlanCatalog::free();
    }

    /**
     * The Cashier subscription "type" this billable's plan is read from. It
     * doubles as the memo key, so an app juggling several subscription types
     * never serves one type's plan for another.
     */
    protected function planSubscriptionType(): string
    {
        return (string) config('kinetix.billing.subscription', 'default');
    }

    public function onPlan(string $slug): bool
    {
        return $this->currentPlan()?->slug === $slug;
    }

    public function canUseFeature(string $path): bool
    {
        return (bool) $this->currentPlan()?->canUseFeature($path);
    }

    public function planFeature(string $path, mixed $default = null): mixed
    {
        $plan = $this->currentPlan();

        return $plan !== null ? $plan->featureValue($path, $default) : $default;
    }

    public function hasReachedPlanLimit(string $path, int $count): bool
    {
        return (bool) $this->currentPlan()?->hasReachedLimit($path, $count);
    }

    /**
     * Units left before the plan limit at $path is reached (floored at zero).
     * Null means unlimited — including when no plan resolves at all, so a
     * billing-less environment never blocks the host app.
     */
    public function remainingPlanLimit(string $path, int $count): ?int
    {
        return $this->currentPlan()?->remainingLimit($path, $count);
    }

    // -- Plan-gating kit (capabilities.* / usage.* convention) ------------------

    /**
     * Whether the plan grants a CAPABILITY — sugar over the
     * `features.capabilities.*` namespace, the same structure the usage
     * meters read on the `usage.*` side:
     *
     *     features: { capabilities: { api: true }, usage: { projects: 10 } }
     *
     * With no resolvable plan, capabilities are DENIED (fail closed) while
     * limits stay unlimited (fail open) — gating features is opt-in per plan,
     * blocking creation never is.
     */
    public function planAllows(string $capability): bool
    {
        return $this->canUseFeature('capabilities.'.$capability);
    }

    /**
     * The plan's limit for a `features.usage.*` key, or null when unlimited
     * (no value on the plan, or no plan at all).
     */
    public function planLimit(string $key): ?int
    {
        $limit = $this->planFeature('usage.'.$key);

        return $limit === null ? null : (int) $limit;
    }

    /**
     * Whether a current usage count still fits the plan's `usage.*` limit.
     * Unlimited (null) is always within.
     */
    public function isWithinPlanLimit(string $key, int $current): bool
    {
        return ! $this->hasReachedPlanLimit('usage.'.$key, $current);
    }
}
