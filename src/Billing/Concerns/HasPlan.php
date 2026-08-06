<?php

declare(strict_types=1);

namespace Happones\Kinetix\Billing\Concerns;

use Happones\Kinetix\Billing\Plan;

/**
 * Add to the Cashier Billable model (User/Team/…) to resolve its current Plan
 * and gate features. Cashier subscription methods are accessed defensively so
 * the trait is safe even before a subscription exists.
 */
trait HasPlan
{
    /**
     * Resolve the billable's current plan from its active subscription's price,
     * or null when there is no matching paid subscription.
     */
    public function currentPlan(): ?Plan
    {
        $type = (string) config('kinetix.billing.subscription', 'default');

        $trialGeneric = (bool) config('kinetix.billing.trial_generic', false);

        if ($trialGeneric && method_exists($this, 'onGenericTrial') && $this->onGenericTrial()) {
            $trialPlanSlug = $this->trial_plan ?? null;

            if ($trialPlanSlug !== null) {
                /** @var class-string<Plan> $model */
                $model = config('kinetix.billing.plan_model', Plan::class);

                return $model::query()->where('slug', $trialPlanSlug)->first();
            }
        }

        if (method_exists($this, 'subscription')) {
            $subscription = $this->subscription($type);

            if ($subscription !== null) {
                $priceId = $subscription->stripe_price ?? null;

                if ($priceId !== null) {
                    /** @var class-string<Plan> $model */
                    $model = config('kinetix.billing.plan_model', Plan::class);

                    $plan = $model::query()
                        ->where('stripe_monthly_price_id', $priceId)
                        ->orWhere('stripe_yearly_price_id', $priceId)
                        ->first();

                    if ($plan !== null) {
                        return $plan;
                    }
                }
            }
        }

        /** @var class-string<Plan> $model */
        $model = config('kinetix.billing.plan_model', Plan::class);

        return $model::query()->active()->ordered()
            ->where(function ($query) {
                $query->where('is_free', true)
                    ->orWhere('monthly_price', '<=', 0);
            })
            ->first();
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
