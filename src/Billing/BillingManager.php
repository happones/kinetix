<?php

declare(strict_types=1);

namespace Happones\Kinetix\Billing;

use Happones\Kinetix\Data\PlanData;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use RuntimeException;

/**
 * Thin, framework-agnostic wrapper around Laravel Cashier for a configurable
 * billable model. Every Cashier call is guarded so a misconfigured billable
 * fails loudly rather than fatally, and all return shapes match the TypeScript
 * billing types consumed by the Vue components.
 */
class BillingManager
{
    public function __construct(protected Model $billable) {}

    public static function for(Model $billable): self
    {
        return new self($billable);
    }

    /**
     * Resolve the billable from the authenticated user via the configured
     * resolver, falling back to the user itself.
     */
    public static function resolve(): self
    {
        $resolver = config('kinetix.billing.resolve_billable');
        $user     = auth()->user();

        if ($resolver) {
            $billable = is_callable($resolver) ? $resolver($user) : $user;
        } elseif (config('kinetix.billing.teams', false)) {
            $request = request();
            $team    = $request->route('team');

            if ($team !== null && ! $team instanceof Model) {
                /** @var class-string<Model> $modelClass */
                $modelClass = config('kinetix.billing.billable', 'App\\Models\\Team');
                if (class_exists($modelClass)) {
                    $modelInstance = new $modelClass;
                    $routeKeyName  = $modelInstance->getRouteKeyName();
                    $team          = $modelClass::query()->where($routeKeyName, $team)->first();
                }
            }

            if ($team === null && $user) {
                $team = $user->currentTeam ?? null;
            }

            $billable = $team ?? $user;
        } else {
            $billable = $user;
        }

        if (! $billable instanceof Model) {
            throw new RuntimeException('Kinetix Billing could not resolve a billable model for the current request.');
        }

        return new self($billable);
    }

    public function billable(): Model
    {
        return $this->billable;
    }

    protected function subscriptionType(): string
    {
        return (string) config('kinetix.billing.subscription', 'default');
    }

    /**
     * All active plans, ordered, as DTOs.
     *
     * @return SupportCollection<int, PlanData>
     */
    public function plans(): SupportCollection
    {
        /** @var class-string<Plan> $model */
        $model = config('kinetix.billing.plan_model', Plan::class);

        /** @var Collection<int, Plan> $plans */
        $plans = $model::query()->active()->ordered()->get();

        return $plans->map(static fn (Plan $plan): PlanData => PlanData::fromPlan($plan))->values();
    }

    public function currentPlan(): ?Plan
    {
        if (method_exists($this->billable, 'currentPlan')) {
            $plan = $this->billable->currentPlan();

            if ($plan !== null) {
                return $plan;
            }
        }

        $trialGeneric = (bool) config('kinetix.billing.trial_generic', false);

        if ($trialGeneric && method_exists($this->billable, 'onGenericTrial') && $this->billable->onGenericTrial()) {
            $trialPlanSlug = $this->billable->trial_plan ?? null;

            if ($trialPlanSlug !== null) {
                /** @var class-string<Plan> $model */
                $model = config('kinetix.billing.plan_model', Plan::class);

                $plan = $model::query()->where('slug', $trialPlanSlug)->first();

                if ($plan !== null) {
                    return $plan;
                }
            }
        }

        return $this->resolveFreePlan();
    }

    protected function resolveFreePlan(): ?Plan
    {
        /** @var class-string<Plan> $model */
        $model = config('kinetix.billing.plan_model', Plan::class);

        return $model::query()->active()->ordered()
            ->where(function ($query) {
                $query->where('is_free', true)
                    ->orWhere('monthly_price', '<=', 0);
            })
            ->first();
    }

    /**
     * Ensure the billable exists as a Stripe customer before issuing intents.
     */
    public function ensureStripeCustomer(): void
    {
        if (($this->billable->stripe_id ?? null) === null && method_exists($this->billable, 'createAsStripeCustomer')) {
            $this->billable->createAsStripeCustomer();
        }
    }

    public function setupIntent(): mixed
    {
        $this->ensureStripeCustomer();

        return method_exists($this->billable, 'createSetupIntent')
            ? $this->billable->createSetupIntent()
            : null;
    }

    /**
     * @return array<int, array{id: string, brand: string, last4: string, expMonth: int, expYear: int}>
     */
    public function paymentMethods(): array
    {
        if (! method_exists($this->billable, 'paymentMethods')) {
            return [];
        }

        return $this->billable->paymentMethods()
            ->map(static fn ($pm): array => [
                'id'       => $pm->id,
                'brand'    => $pm->card->brand,
                'last4'    => $pm->card->last4,
                'expMonth' => $pm->card->exp_month,
                'expYear'  => $pm->card->exp_year,
            ])
            ->values()
            ->all();
    }

    public function defaultPaymentMethodId(): ?string
    {
        if (! method_exists($this->billable, 'defaultPaymentMethod')) {
            return null;
        }

        return $this->billable->defaultPaymentMethod()?->id;
    }

    /**
     * @return array<int, array{id: string, date: string, total: string, status: string, url: string}>
     */
    public function invoices(): array
    {
        if (! method_exists($this->billable, 'invoices')) {
            return [];
        }

        return $this->billable->invoices()
            ->map(static fn ($invoice): array => [
                'id'     => $invoice->id,
                'date'   => $invoice->date()->toFormattedDateString(),
                'total'  => (string) $invoice->total(),
                'status' => (string) $invoice->status,
                'url'    => (string) $invoice->invoice_pdf,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{active: bool, onGracePeriod: bool, status: ?string, endsAt: ?string, stripePrice: ?string, onTrial: bool, trialEndsAt: ?string, onGenericTrial: bool, trialPlan: ?string}
     */
    public function subscriptionData(): array
    {
        /** @var object|null $subscription */
        $subscription = $this->subscription();

        $trialGeneric       = (bool) config('kinetix.billing.trial_generic', false);
        $onGenericTrial     = $trialGeneric   && method_exists($this->billable, 'onGenericTrial') && $this->billable->onGenericTrial();
        $genericTrialEndsAt = $onGenericTrial && method_exists($this->billable, 'trialEndsAt')
            ? $this->billable->trialEndsAt($this->subscriptionType())?->toIso8601String()
            : null;

        $onTrial     = false;
        $trialEndsAt = null;

        if (! $trialGeneric && $subscription !== null) {
            $onTrial     = method_exists($subscription, 'onTrial') && $subscription->onTrial();
            $trialEndsAt = $onTrial ? $subscription->trial_ends_at?->toIso8601String() : null;
        }

        return [
            'active'         => $subscription !== null && (bool) ($subscription->active() ?? false),
            'onGracePeriod'  => $subscription !== null && (bool) ($subscription->onGracePeriod() ?? false),
            'status'         => $subscription !== null ? (string) ($subscription->stripe_status ?? '') : null,
            'endsAt'         => $subscription !== null ? $subscription->ends_at?->toIso8601String() : null,
            'stripePrice'    => $subscription !== null ? $subscription->stripe_price ?? null : null,
            'onTrial'        => $onTrial || $onGenericTrial,
            'trialEndsAt'    => $trialEndsAt ?? $genericTrialEndsAt,
            'onGenericTrial' => $onGenericTrial,
            'trialPlan'      => $onGenericTrial ? ($this->billable->trial_plan ?? null) : null,
        ];
    }

    protected function subscription(): mixed
    {
        if (! method_exists($this->billable, 'subscription')) {
            return null;
        }

        return $this->billable->subscription($this->subscriptionType());
    }

    protected function subscribed(): bool
    {
        return method_exists($this->billable, 'subscribed')
            && $this->billable->subscribed($this->subscriptionType());
    }

    /**
     * Subscribe to, swap to, or downgrade from a plan. Free plans cancel the
     * current paid subscription (downgrade); paid plans create or swap.
     * When trial_generic is active and the plan has trial_days, a generic
     * trial is set on the billable model instead of creating a Stripe
     * subscription — no payment method is required.
     */
    public function subscribe(string $planSlug, ?string $paymentMethod = null, string $cycle = 'monthly'): void
    {
        /** @var class-string<Plan> $model */
        $model = config('kinetix.billing.plan_model', Plan::class);

        /** @var Plan $plan */
        $plan = $model::query()->where('slug', $planSlug)->firstOrFail();

        $trialGeneric = (bool) config('kinetix.billing.trial_generic', false);
        $planHasTrial = $plan->trial_days !== null && $plan->trial_days > 0;

        // Downgrade: a free plan means cancel any active paid subscription.
        if ($plan->isFree()) {
            if ($this->subscribed()) {
                $this->subscription()->cancel();
            }

            return;
        }

        // When trial_generic is active and the plan has trial days, set up a
        // generic trial on the billable instead of creating a Stripe
        // subscription. This allows subscribing without a payment method.
        if ($trialGeneric && $planHasTrial) {
            if ($this->subscribed()) {
                $this->subscription()->cancel();
            }

            $this->billable->forceFill([
                'trial_ends_at' => now()->addDays($plan->trial_days),
                'trial_plan'    => $planSlug,
            ])->save();

            return;
        }

        $priceId = $plan->stripePriceId($cycle);

        if ($priceId === null) {
            throw new RuntimeException("Plan [{$planSlug}] has no Stripe price id for the [{$cycle}] cycle.");
        }

        if ($this->subscribed()) {
            $subscription = $this->subscription();

            if ($subscription->onGracePeriod()) {
                $subscription->resume();
            }

            $subscription->swap($priceId);

            return;
        }

        // Clear any active generic trial when creating a real Stripe subscription.
        if ($trialGeneric && method_exists($this->billable, 'onGenericTrial') && $this->billable->onGenericTrial()) {
            $this->billable->forceFill([
                'trial_ends_at' => null,
                'trial_plan'    => null,
            ])->save();
        }

        $hasTrial                = ! $trialGeneric && $planHasTrial;
        $hasDefaultPaymentMethod = method_exists($this->billable, 'hasDefaultPaymentMethod')
            && $this->billable->hasDefaultPaymentMethod();

        if ($paymentMethod === null && ! $hasTrial && ! $hasDefaultPaymentMethod) {
            throw new RuntimeException('A payment method is required to start a new subscription.');
        }

        $builder = $this->billable->newSubscription($this->subscriptionType(), $priceId);

        if ($hasTrial) {
            $builder->trialDays($plan->trial_days);
        }

        $builder->create($paymentMethod);
    }

    public function addPaymentMethod(string $paymentMethod): void
    {
        $this->ensureStripeCustomer();
        $this->billable->addPaymentMethod($paymentMethod);

        if (! $this->billable->hasDefaultPaymentMethod()) {
            $this->billable->updateDefaultPaymentMethod($paymentMethod);
        }
    }

    public function removePaymentMethod(string $id): void
    {
        $paymentMethod = $this->billable->findPaymentMethod($id);

        $paymentMethod?->delete();
    }

    public function downloadInvoice(string $invoiceId): mixed
    {
        return $this->billable->downloadInvoice($invoiceId, [
            'vendor'  => (string) config('app.name'),
            'product' => (string) config('kinetix.billing.product', 'Subscription'),
        ]);
    }

    public function cancel(): void
    {
        if ($this->subscribed()) {
            $this->subscription()->cancel();
        }
    }

    public function resume(): void
    {
        $subscription = $this->subscription();

        if ($subscription !== null && $subscription->onGracePeriod()) {
            $subscription->resume();
        }
    }
}
