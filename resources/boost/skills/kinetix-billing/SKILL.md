---
name: kinetix-billing
description: "Optional Cashier + Stripe billing module for Kinetix: plans with dot-path feature gating, subscriptions, payment methods (Stripe Elements themed with shadcn tokens), invoices, and the pricing/subscription Vue components. Activates when building plans, pricing tables, the BillingManager/BillingController, plan-feature middleware, or the billing page."
license: MIT
metadata:
  author: happones
---

# Kinetix Billing Development

## When to Apply

Activate this skill when:
- Defining `Plan` records or feature-gating logic (dot-path `features`, `canUseFeature`, `hasReachedLimit`).
- Wiring subscriptions/payment-methods/invoices via `BillingManager` or `BillingController`.
- Building or customising the billing Vue surface (`KinetixPricingTable`, `KinetixPlanCard`, `KinetixPaymentMethods`, `KinetixSubscriptionStatus`, `KinetixInvoicesTable`, `KinetixUsageMeters`) or the `useKinetixBilling` / `useKinetixStripe` composables.
- Gating routes with `plan.feature` middleware, or scaffolding via `kinetix:make-billing`.
- Showing progress for **metered** Stripe prices (API calls, seats, storage, …) — `UsageMetric`, `BillingManager::usage()`/`reportUsage()`, `<KinetixUsageMeters>`.

## Documentation

Full reference: [Kinetix Billing Documentation](https://happones.github.io/kinetix/billing).

## Key Rules

- **Optional by design.** Billing is off unless `kinetix.billing.enabled`. Cashier is a *suggested* dep — guard every Cashier call (the `Billable`/`HasPlan` traits live on the host's configurable billable, not in the package). Never `use Laravel\Cashier\...` at module load time without a `method_exists`/class guard.
- **Generic, not app-specific.** Plan capabilities are a nested JSON `features` map resolved by dot-path. Render capability rows from a `featureLabels` (dot-path → label) prop — never hardcode app-specific feature keys in components.
- **Free = downgrade.** `BillingManager::subscribe()` treats `$plan->isFree()` (`is_free` column or `monthly_price <= 0`) as the cancel/downgrade path; paid plans swap (resume-if-grace) or create (requires a payment method). No hardcoded plan slugs.
- **Generic trial.** When `trial_generic` is enabled and the plan has `trial_days`, `subscribe()` sets `trial_ends_at` and `trial_plan` on the billable model instead of creating a Stripe subscription. The consumer must add a `trial_plan` (nullable string) column to the billable's table. `HasPlan::currentPlan()` returns the trial plan while the trial is active, then falls back to Stripe.
- **Stripe Elements + shadcn.** The card field is a cross-origin iframe and cannot inherit CSS. `useKinetixStripe` resolves shadcn tokens to `rgb()` via a probe element and re-applies them on `<html>` theme toggles (MutationObserver). It tears the Element + observer down on unmount — keep it leak-safe. Always verify both light and dark mode.
- **Decouple the routes.** Components emit events; `useKinetixBilling(endpoints)` performs the Inertia visits with URL strings the host resolves (Ziggy/Wayfinder/plain).
- **i18n + tokens.** Components are token-only and take labels via props (English defaults). Keep the `trans('kinetix.billing_*')` keys in sync across en/es/fr/pt/zh/ja/ru.
- **Plans are read from a catalog, never re-queried.** `currentPlan()` is memoized per billable per request and answered from `PlanCatalog` (the whole `plans` table, loaded at most once). Gating a dozen things costs ONE query. Model writes and bulk `Plan::query()->update()` flush it automatically; raw `DB::table('plans')` writes do NOT — that's why `billing.cache.ttl` (the opt-in cross-request layer) defaults to null. Never add a plan query to a hot path: ask `HasPlan`. In a worker that changed a subscription mid-process, call `$billable->forgetCurrentPlan()`.
- **Metered usage is customizable by design.** Kinetix cannot know how your app measures "used" — the billable defines `meteredUsage(?Plan $plan): array<UsageMetric>` (hybrid-detected via `method_exists`; implementing `Contracts\ProvidesUsageMetrics` is optional). The **limit** and **color** are each independently overridable per metric (`->limit()`, `->color(string|Closure)`) or left to fall back to the plan's `features.usage.{key}` and the default thresholds, respectively — don't hardcode either in the component.

## Usage Guide

### 1. Plan + feature gating

```php
use Happones\Kinetix\Billing\Plan;

$plan = Plan::create([
    'name' => 'Pro', 'monthly_price' => 29, 'yearly_price' => 290,
    'stripe_monthly_price_id' => 'price_...',
    'features' => ['usage' => ['projects' => null], 'capabilities' => ['api' => true]],
    'highlighted_features' => ['Unlimited projects', 'API access'],
]);

$user->canUseFeature('capabilities.api');         // via HasPlan on the billable
$user->hasReachedPlanLimit('usage.projects', 10); // null limit = unlimited

// Namespaced sugar (features: { capabilities: {...}, usage: {...} }):
$user->planAllows('api');                // capabilities are DENIED without a plan (fail closed)
$user->planLimit('projects');            // ?int — null = unlimited (fail open)
$user->isWithinPlanLimit('projects', 5);
```

**Creation limits**: `use EnforcesPlanLimits` on a model → creating past the plan's
`usage.{plural_snake_model}` limit throws `PlanLimitExceededException` (403, translated).
Overridables: `planLimitKey()`, `planLimitBillable()` (defaults to the standard billable
resolution), `planLimitQuery($billable)` (defaults to narrowing by the billable's conventional
FK when the creating record carries it). Unlimited plans skip the COUNT; billing-less
environments skip the check entirely. `$model->enforcePlanLimit()` runs it manually.

> **Gated by MORE than the plan?** A feature behind a plan capability AND a
> feature flag AND a role belongs in an **entitlement**, not a hand-written
> `&&` chain — see the `kinetix-entitlements` skill. It short-circuits (the
> per-user check never runs once the plan refused) and reports WHICH layer
> denied, so the UI can padlock instead of just greying out.

### 2. Orchestration

```php
use Happones\Kinetix\Billing\BillingManager;

BillingManager::for($billable)->subscribe('pro', $paymentMethod, 'monthly');
```

### 3. Route gating

```php
Route::post('/export', ...)->middleware('plan.feature:capabilities.api'); // dot-path, plain 403
Route::post('/export', ...)->middleware('kinetix.plan:api');              // capability, upsell-aware
```

`kinetix.plan:` redirects denied WEB requests to `kinetix.billing.upgrade_url`
(`KINETIX_BILLING_UPGRADE_URL`, e.g. `/billing`) with a flash toast; JSON (or no URL) → 403.
Frontend: `<KinetixPlanGate feature="capabilities.api">` = `<KinetixPlanFeature>` with a built-in
lock-card + Upgrade CTA denied state (`#locked` slot overrides); `useKinetixPlan()` adds
`allows('api')` + `upgradeUrl`.

`<KinetixPlanLock>` is the full padlock: same gating props (`feature`/`limit`+`count`), four
presentations via `variant` — `card` (dashed lock card replacing the content), `overlay`
(content stays visible, blurred + `inert`, under the lock), `banner` (row-shaped upsell strip),
`badge` (content dimmed with a padlock appended; clicking it opens the upgrade dialog instead of
navigating). Copy props: `featureName` (woven into the default body copy), `title`,
`description`, `ctaLabel`, `badgeLabel` ('Pro' pill); behaviour props: `modal` (CTA opens
`<KinetixUpgradeModal>` vs linking out), `blur` (overlay), `upgradeUrl`. App-wide defaults live
in `kinetix.billing.lock` (`variant`/`modal`/`blur`/`badge_label`) — per-instance props win. A
lock with NO `feature`/`limit` is an unconditional upsell (standalone banners). `#locked`
replaces the lock UI and receives `{ remaining, open }`. No upgrade URL = no CTA rendered.

```vue
<KinetixPlanLock variant="overlay" feature="alerts.discord" feature-name="Discord alerts">
    <DiscordSettings />
</KinetixPlanLock>

<KinetixPlanLock variant="badge" feature="capabilities.api">
    <SidebarLink href="/api-tokens">API tokens</SidebarLink>
</KinetixPlanLock>
```

### 3b. Blank is not an id (REQUIRED when touching Stripe identifiers)

Kinetix treats `''` as absent everywhere a Stripe id is read — `''` is what a form default or an
import leaves in a column, and forwarding it produces an opaque Stripe error far from the cause.
`BillingManager::hasStripeCustomer()` is stricter than Cashier's `hasStripeId()` (a plain null
check): a blank `stripe_id` is NOT a customer, and `ensureStripeCustomer()` clears it before
creating one (otherwise Cashier throws `CustomerAlreadyCreated` and the billable stays stuck
forever). A blank `payment_method` means "none given" (`subscribe()` takes the no-card path;
`addPaymentMethod('')` throws); a blank plan price means no price for that cycle
(`Plan::stripePriceId()` returns null); a blank subscription price never matches a plan — plans
seeded with `''` price columns would otherwise all match it and grant the wrong features. Use
`filled()`/`blank()`, never `!== null`, for these.

### 4. Metered usage tracking + credits

`use HasMeteredUsage` on the billable (tables via `--tag=kinetix-billing-migrations`):
`consume($key, $n)` (atomic, throws `UsageLimitExceededException` 403 past allowance+credits,
allowance spent before credits), `canConsume`, `currentUsage` (resets per calendar month —
override `usagePeriodKey()`), `remainingUsage` (null = unlimited; unlimited never blocks),
`addCredits`/`creditsFor` (credits persist across months). Ships a default `meteredUsage()` so
`<KinetixUsageMeters>` shows real tracked numbers per plan `usage.*` key (limit becomes
allowance+credits when credits exist). Override `meteredUsage()` for COUNT/SUM-style metrics.

### 5. Metered usage progress

```php
use Happones\Kinetix\Billing\UsageMetric;

// On the billable (Team/User + Billable + HasPlan):
public function meteredUsage(?\Happones\Kinetix\Billing\Plan $plan): array
{
    return [
        UsageMetric::make('api_calls')->label('API calls')->used($this->apiCallsThisPeriod()),
    ];
}
```

```vue
<KinetixUsageMeters :metrics="usage" />  <!-- usage prop from BillingController::index() -->
```

Report the usage back to Stripe (write side): `BillingManager::for($billable)->reportUsage($quantity, $meteredPriceId)`.

### 6. Scaffold

```bash
php artisan kinetix:make-billing --seeder
```

## Files

- `src/Billing/Plan.php` · `Concerns/HasPlan.php` · `BillingManager.php` · `BillingController.php` · `BillingRoutes.php` · `Middleware/PlanFeatureMiddleware.php`
- `src/Billing/UsageMetric.php` · `Contracts/ProvidesUsageMetrics.php`
- `src/Data/PlanData.php` · `UsageMetricData.php`
- `resources/js/components/KinetixPricingTable|KinetixPlanCard|KinetixPaymentMethods|KinetixSubscriptionStatus|KinetixInvoicesTable|KinetixUsageMeters.vue`
- `resources/js/components/KinetixPlanFeature|KinetixPlanGate|KinetixPlanLock|KinetixUpgradeModal.vue` · `components/Billing/PlanLockPanel|PlanLockCta.vue`
- `resources/js/composables/useKinetixBilling.ts` · `useKinetixStripe.ts` · `useKinetixPlan.ts`
- `database/migrations/*_create_kinetix_plans_table.php`
- `src/Commands/MakeBillingCommand.php`
