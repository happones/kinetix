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
```

### 2. Orchestration

```php
use Happones\Kinetix\Billing\BillingManager;

BillingManager::for($billable)->subscribe('pro', $paymentMethod, 'monthly');
```

### 3. Route gating

```php
Route::post('/export', ...)->middleware('plan.feature:capabilities.api');
```

### 4. Metered usage progress

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

### 5. Scaffold

```bash
php artisan kinetix:make-billing --seeder
```

## Files

- `src/Billing/Plan.php` · `Concerns/HasPlan.php` · `BillingManager.php` · `BillingController.php` · `BillingRoutes.php` · `Middleware/PlanFeatureMiddleware.php`
- `src/Billing/UsageMetric.php` · `Contracts/ProvidesUsageMetrics.php`
- `src/Data/PlanData.php` · `UsageMetricData.php`
- `resources/js/components/KinetixPricingTable|KinetixPlanCard|KinetixPaymentMethods|KinetixSubscriptionStatus|KinetixInvoicesTable|KinetixUsageMeters.vue`
- `resources/js/composables/useKinetixBilling.ts` · `useKinetixStripe.ts`
- `database/migrations/*_create_kinetix_plans_table.php`
- `src/Commands/MakeBillingCommand.php`
