<?php

declare(strict_types=1);

namespace Happones\Kinetix\Billing;

use Happones\Kinetix\Data\PlanData;
use Happones\Kinetix\Data\UsageMetricData;
use Happones\Kinetix\Support\KinetixTeams;
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

    // -------------------------------------------------------------------
    // Static factories
    // -------------------------------------------------------------------

    public static function for(Model $billable): self
    {
        return new self($billable);
    }

    public static function resolve(): self
    {
        $user     = auth()->user();
        $billable = static::resolveConfiguredBillable($user);

        if (! $billable instanceof Model) {
            throw new RuntimeException('Kinetix Billing could not resolve a billable model for the current request.');
        }

        return new self($billable);
    }

    protected static function resolveConfiguredBillable($user): mixed
    {
        $resolver = config('kinetix.billing.resolve_billable');

        if ($resolver) {
            return is_callable($resolver) ? $resolver($user) : $user;
        }

        if (KinetixTeams::enabledFor('billing') || config('kinetix.tenancy.subdomain') !== null) {
            return static::resolveTeam($user) ?? $user;
        }

        return $user;
    }

    protected static function resolveTeam($user): ?Model
    {
        $subdomainColumn = config('kinetix.tenancy.subdomain');

        if ($subdomainColumn !== null) {
            return static::findTeamFromSubdomain($subdomainColumn) ?? ($user->currentTeam ?? null);
        }

        return static::findTeamFromRoute() ?? ($user->currentTeam ?? null);
    }

    protected static function findTeamFromSubdomain(string $column): ?Model
    {
        $host  = request()->getHost();
        $parts = explode('.', $host);

        if (count($parts) < 2) {
            return null;
        }

        $subdomain = $parts[0];

        /** @var class-string<Model> $modelClass */
        $modelClass = config('kinetix.billing.billable', 'App\\Models\\Team');

        if (! class_exists($modelClass)) {
            return null;
        }

        return $modelClass::query()->where($column, $subdomain)->first();
    }

    protected static function findTeamFromRoute(): ?Model
    {
        $request = request();
        $team    = $request->route('team');

        if ($team instanceof Model) {
            return $team;
        }

        if ($team === null) {
            return null;
        }

        /** @var class-string<Model> $modelClass */
        $modelClass = config('kinetix.billing.billable', 'App\\Models\\Team');

        if (! class_exists($modelClass)) {
            return null;
        }

        $modelInstance = new $modelClass;

        return $modelClass::query()->where($modelInstance->getRouteKeyName(), $team)->first();
    }

    // -------------------------------------------------------------------
    // Public accessors
    // -------------------------------------------------------------------

    public function billable(): Model
    {
        return $this->billable;
    }

    /**
     * All active plans, ordered, as DTOs.
     *
     * @return SupportCollection<int, PlanData>
     */
    public function plans(): SupportCollection
    {
        /** @var Collection<int, Plan> $plans */
        $plans = $this->planModel()::query()->active()->ordered()->get();

        return $plans->map(static fn (Plan $plan): PlanData => PlanData::fromPlan($plan))->values();
    }

    public function currentPlan(): ?Plan
    {
        return $this->billableCurrentPlan()
            ?? $this->genericTrialPlan()
            ?? $this->resolveFreePlan();
    }

    // -------------------------------------------------------------------
    // Stripe helpers
    // -------------------------------------------------------------------

    public function ensureStripeCustomer(): void
    {
        if (($this->billable->stripe_id ?? null) !== null) {
            return;
        }

        if (! method_exists($this->billable, 'createAsStripeCustomer')) {
            return;
        }

        $this->billable->createAsStripeCustomer();
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

    // -------------------------------------------------------------------
    // Subscription data
    // -------------------------------------------------------------------

    /**
     * @return array{active: bool, onGracePeriod: bool, status: ?string, endsAt: ?string, stripePrice: ?string, onTrial: bool, trialEndsAt: ?string, onGenericTrial: bool, trialPlan: ?string}
     */
    public function subscriptionData(): array
    {
        $subscription = $this->subscription();

        return [
            'active'        => $subscription !== null && (bool) ($subscription->active() ?? false),
            'onGracePeriod' => $subscription !== null && (bool) ($subscription->onGracePeriod() ?? false),
            'status'        => $subscription !== null ? (string) ($subscription->stripe_status ?? '') : null,
            'endsAt'        => $subscription !== null ? $subscription->ends_at?->toIso8601String() : null,
            'stripePrice'   => $subscription !== null ? $subscription->stripe_price ?? null : null,
            ...$this->resolveTrialData($subscription),
        ];
    }

    /**
     * Metered usage for the current billing period, for the
     * `<KinetixUsageMeters>` progress display. Empty unless the billable
     * implements {@see Contracts\ProvidesUsageMetrics} (or just exposes a
     * `meteredUsage(?Plan $plan): array` method — hybrid detection) — that
     * app-specific logic is the only piece Kinetix can't know on its own
     * (how many API calls, seats, GB were actually consumed).
     *
     * @return array<int, UsageMetricData>
     */
    public function usage(): array
    {
        if (! method_exists($this->billable, 'meteredUsage')) {
            return [];
        }

        $plan = $this->currentPlan();

        /** @var array<int, UsageMetric> $metrics */
        $metrics = (array) $this->billable->meteredUsage($plan);

        return array_map(
            static fn (UsageMetric $metric): UsageMetricData => UsageMetricData::fromMetric($metric, $plan),
            $metrics,
        );
    }

    // -------------------------------------------------------------------
    // Subscription lifecycle
    // -------------------------------------------------------------------

    public function subscribe(string $planSlug, ?string $paymentMethod = null, string $cycle = 'monthly'): void
    {
        $plan = $this->resolvePlan($planSlug);

        if ($plan->isFree()) {
            $this->cancelActiveSubscription();
            $this->clearGenericTrialIfActive();

            return;
        }

        if ($this->shouldUseGenericTrial($plan)) {
            $this->startGenericTrial($planSlug, $plan);

            return;
        }

        $this->handlePaidSubscription($plan, $planSlug, $paymentMethod, $cycle);
    }

    public function addPaymentMethod(string $paymentMethod): void
    {
        $this->ensureStripeCustomer();
        $this->billable->addPaymentMethod($paymentMethod);

        if ($this->billable->hasDefaultPaymentMethod()) {
            return;
        }

        $this->billable->updateDefaultPaymentMethod($paymentMethod);
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

    /**
     * Report metered usage to Stripe for the active subscription (Cashier's
     * `SubscriptionItem::reportUsage()`) — the write-side companion to
     * {@see usage()}'s read-only progress display. A no-op when there is no
     * active subscription or the price id doesn't match a subscription item.
     *
     * @param string|null $priceId the metered price's Stripe id; null uses the
     *                             subscription's single item (a plan with more
     *                             than one price must pass it explicitly)
     */
    public function reportUsage(int $quantity = 1, ?string $priceId = null): void
    {
        $subscription = $this->subscription();

        if ($subscription === null) {
            return;
        }

        if ($priceId !== null && method_exists($subscription, 'reportUsageFor')) {
            $subscription->reportUsageFor($priceId, $quantity);

            return;
        }

        if (method_exists($subscription, 'reportUsage')) {
            $subscription->reportUsage($quantity);
        }
    }

    public function cancel(): void
    {
        if ($this->subscribed()) {
            $this->subscription()->cancel();
        }

        $this->clearGenericTrialIfActive();
    }

    public function resume(): void
    {
        $subscription = $this->subscription();

        if ($subscription === null) {
            return;
        }

        if (! $subscription->onGracePeriod()) {
            return;
        }

        $subscription->resume();
    }

    // -------------------------------------------------------------------
    // Config helpers
    // -------------------------------------------------------------------

    /**
     * @return class-string<Plan>
     */
    protected function planModel(): string
    {
        return config('kinetix.billing.plan_model', Plan::class);
    }

    protected function isTrialGenericEnabled(): bool
    {
        return (bool) config('kinetix.billing.trial_generic', false);
    }

    protected function subscriptionType(): string
    {
        return (string) config('kinetix.billing.subscription', 'default');
    }

    // -------------------------------------------------------------------
    // Cashier guards
    // -------------------------------------------------------------------

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

    protected function hasDefaultPaymentMethod(): bool
    {
        return method_exists($this->billable, 'hasDefaultPaymentMethod')
            && $this->billable->hasDefaultPaymentMethod();
    }

    protected function isOnGenericTrial(): bool
    {
        if (! $this->isTrialGenericEnabled()) {
            return false;
        }

        return method_exists($this->billable, 'onGenericTrial')
            && $this->billable->onGenericTrial();
    }

    protected function hasAlreadyUsedTrial(): bool
    {
        return $this->billable->getAttribute('trial_taken_at') !== null;
    }

    // -------------------------------------------------------------------
    // Current plan resolution
    // -------------------------------------------------------------------

    protected function billableCurrentPlan(): ?Plan
    {
        if (! method_exists($this->billable, 'currentPlan')) {
            return null;
        }

        return $this->billable->currentPlan() ?: null;
    }

    protected function genericTrialPlan(): ?Plan
    {
        if (! $this->isOnGenericTrial()) {
            return null;
        }

        $slug = $this->billable->trial_plan ?? null;

        if ($slug === null) {
            return null;
        }

        return $this->planModel()::query()->where('slug', $slug)->first();
    }

    protected function resolveFreePlan(): ?Plan
    {
        return $this->planModel()::query()->active()->ordered()
            ->where(function ($query) {
                $query->where('is_free', true)
                    ->orWhere('monthly_price', '<=', 0);
            })
            ->first();
    }

    // -------------------------------------------------------------------
    // Trial resolution
    // -------------------------------------------------------------------

    /**
     * @param  object|null                                                                          $subscription
     * @return array{onTrial: bool, trialEndsAt: ?string, onGenericTrial: bool, trialPlan: ?string}
     */
    protected function resolveTrialData($subscription): array
    {
        if ($this->isOnGenericTrial()) {
            return [
                'onTrial'        => true,
                'trialEndsAt'    => $this->genericTrialEndsAt(),
                'onGenericTrial' => true,
                'trialPlan'      => $this->billable->trial_plan ?? null,
            ];
        }

        $stripeTrial = $this->isOnStripeTrial($subscription);

        return [
            'onTrial'        => $stripeTrial,
            'trialEndsAt'    => $stripeTrial ? $subscription->trial_ends_at?->toIso8601String() : null,
            'onGenericTrial' => false,
            'trialPlan'      => null,
        ];
    }

    protected function isOnStripeTrial($subscription): bool
    {
        if ($this->isTrialGenericEnabled()) {
            return false;
        }

        if ($subscription === null) {
            return false;
        }

        return method_exists($subscription, 'onTrial') && $subscription->onTrial();
    }

    protected function genericTrialEndsAt(): ?string
    {
        if (! method_exists($this->billable, 'trialEndsAt')) {
            return null;
        }

        return $this->billable->trialEndsAt($this->subscriptionType())?->toIso8601String();
    }

    // -------------------------------------------------------------------
    // Subscribe subroutines
    // -------------------------------------------------------------------

    protected function resolvePlan(string $planSlug): Plan
    {
        /** @var Plan $plan */
        $plan = $this->planModel()::query()->where('slug', $planSlug)->firstOrFail();

        return $plan;
    }

    protected function shouldUseGenericTrial(Plan $plan): bool
    {
        if (! $this->isTrialGenericEnabled()) {
            return false;
        }

        if ($plan->trial_days === null || $plan->trial_days <= 0) {
            return false;
        }

        return ! $this->hasAlreadyUsedTrial();
    }

    protected function cancelActiveSubscription(): void
    {
        if (! $this->subscribed()) {
            return;
        }

        $this->subscription()->cancel();
    }

    protected function startGenericTrial(string $planSlug, Plan $plan): void
    {
        $this->cancelActiveSubscription();
        $this->setGenericTrialData($plan->trial_days, $planSlug);
    }

    protected function handlePaidSubscription(Plan $plan, string $planSlug, ?string $paymentMethod, string $cycle): void
    {
        $priceId = $plan->stripePriceId($cycle);

        if ($priceId === null) {
            throw new RuntimeException("Plan [{$planSlug}] has no Stripe price id for the [{$cycle}] cycle.");
        }

        if ($this->subscribed()) {
            $this->swapExistingSubscription($priceId);

            return;
        }

        $this->clearGenericTrialIfActive();
        $this->validatePaymentMethod($paymentMethod, $plan);
        $this->createNewSubscription($priceId, $paymentMethod, $plan);
    }

    protected function swapExistingSubscription(string $priceId): void
    {
        $subscription = $this->subscription();

        if ($subscription->onGracePeriod()) {
            $subscription->resume();
        }

        $subscription->swap($priceId);
    }

    protected function clearGenericTrialIfActive(): void
    {
        if (! $this->isOnGenericTrial()) {
            return;
        }

        $this->setGenericTrialData(null, null);
    }

    protected function setGenericTrialData(?int $trialDays, ?string $planSlug): void
    {
        $data = [
            'trial_ends_at' => $trialDays !== null ? now()->addDays($trialDays) : null,
            'trial_plan'    => $planSlug,
        ];

        if ($trialDays !== null) {
            $data['trial_taken_at'] = now();
        }

        $this->billable->forceFill($data)->save();
    }

    protected function validatePaymentMethod(?string $paymentMethod, Plan $plan): void
    {
        if ($paymentMethod !== null) {
            return;
        }

        if (! $this->isTrialGenericEnabled() && $plan->trial_days !== null && $plan->trial_days > 0) {
            return;
        }

        if ($this->hasDefaultPaymentMethod()) {
            return;
        }

        if ($this->hasAlreadyUsedTrial()) {
            throw new RuntimeException('You have already used your free trial. A payment method is required to subscribe.');
        }

        throw new RuntimeException('A payment method is required to start a new subscription.');
    }

    protected function createNewSubscription(string $priceId, ?string $paymentMethod, Plan $plan): void
    {
        $builder = $this->billable->newSubscription($this->subscriptionType(), $priceId);

        if (! $this->isTrialGenericEnabled() && $plan->trial_days !== null && $plan->trial_days > 0) {
            $builder->trialDays($plan->trial_days);
        }

        $builder->create($paymentMethod);
    }
}
