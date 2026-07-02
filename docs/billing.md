# Kinetix Billing (Cashier + Stripe)

An **optional** billing/pricing module that wraps [Laravel Cashier](https://laravel.com/docs/billing) behind Kinetix classes and Vue components. Drop a pricing table, payment-method manager, subscription status, and invoices list into any project by calling the components and classes — no bespoke billing code required.

> Billing is off by default and Cashier is a **suggested** dependency. Nothing here loads until you enable it.

---

## 1. Installation

```bash
# 1. Cashier (the billing engine)
composer require laravel/cashier

# 2. Publish Cashier's migrations (customize if needed before running)
php artisan vendor:publish --tag="cashier-migrations"
php artisan migrate

# 3. The Kinetix `plans` table
php artisan vendor:publish --tag=kinetix-billing-migrations
php artisan migrate

# 4. Scaffold the billing page (+ optional example plans)
php artisan kinetix:make-billing --seeder
```

Install `@stripe/stripe-js` on the frontend for secure card processing:
```bash
npm install @stripe/stripe-js
```
Make sure Stripe.js is available on the billing page (the module will automatically detect the npm package or a global script tag if loaded via `<script src="https://js.stripe.com/v3/"></script>`).

---

## 2. Prepare the billable model

Add Cashier's `Billable` trait and Kinetix's `HasPlan` trait to whatever model owns the subscription (a `User`, `Team`, `Organization`, …):

```php
use Laravel\Cashier\Billable;
use Happones\Kinetix\Billing\Concerns\HasPlan;

class User extends Authenticatable
{
    use Billable;
    use HasPlan;
}
```

If the billable is **not** the authenticated user (e.g. the user's current team), point the resolver at it in `config/kinetix.php`:

```php
'billing' => [
    'resolve_billable' => fn ($user) => $user->currentTeam,
],
```

### Team Billing Setup

If you want to bill **Teams** (or any model other than the default `User`), follow these additional steps:

1. **Cashier Migrations**:
   In the Cashier migrations published during installation, change all foreign key references from `users` (or `user_id`) to `teams` (or `team_id`) to attach cashier tables to the Team model instead of Users.

2. **Register Customer Model**:
   Inside the `boot` method of your `AppServiceProvider`, register the `Team` model as Cashier's customer model:
   ```php
   use Laravel\Cashier\Cashier;
   use App\Models\Team;

   public function boot(): void
   {
       Cashier::useCustomerModel(Team::class);
   }
   ```

3. **Configure Kinetix Billing**:
   In your `.env` file, enable team-scoped billing and configure the billable model:
   ```env
   KINETIX_BILLING_TEAMS=true
   KINETIX_BILLING_BILLABLE="App\Models\Team"
   ```

   When `KINETIX_BILLING_TEAMS` (or `kinetix.billing.teams` config key) is `true`:
   * Billing routes are automatically prefixed with `teams/{team}/billing` instead of `billing`.
   * `BillingManager::resolve()` will automatically extract the current team from the `{team}` route parameter (or fall back to the user's `currentTeam` relation).
   * Make sure to update the URLs in your client-side page (`Billing/Index.vue`) to prepend `/teams/${currentTeam.id}` (or similar) to match these routes.

---

## 3. Configuration

`config/kinetix.php` → `billing`:

| Key | Default | Purpose |
|---|---|---|
| `enabled` | `false` | Master switch for the module |
| `teams` | `false` | Enable Team-scoped billing routes and automatic resolution |
| `billable` | `App\Models\User` | Documented billable class |
| `plan_model` | `Happones\Kinetix\Billing\Plan` | Swap for your own Plan subclass |
| `subscription` | `default` | Cashier subscription "type" |
| `currency` / `currency_symbol` | `USD` / `$` | UI formatting |
| `product` | `Subscription` | Label on downloaded invoices |
| `view` | `Billing/Index` | Inertia page the controller renders |
| `resolve_billable` | `null` | Closure to resolve a non-user billable |
| `auto_routes` | `false` | Auto-register the bundled routes |
| `route_prefix` / `route_name` | `billing` / `billing.` | Route group prefix + name |
| `middleware` | `['web', 'auth']` | Route group middleware |

---

## 4. Plans

`Happones\Kinetix\Billing\Plan` is a ready Eloquent model on the `plans` table. `features` is a nested JSON structure resolved by dot-path (usage limits + capability flags).

```php
use Happones\Kinetix\Billing\Plan;

Plan::create([
    'name'                    => 'Pro',
    'monthly_price'           => 29,
    'yearly_price'            => 290,
    'stripe_monthly_price_id' => 'price_...',
    'stripe_yearly_price_id'  => 'price_...',
    'features' => [
        'usage'        => ['projects' => null], // null = unlimited
        'capabilities' => ['api' => true, 'sso' => false],
    ],
    'highlighted_features' => ['Unlimited projects', 'API access'],
    'is_featured' => true,
]);
```

The slug is generated from the name automatically. Feature-gating helpers:

| Method | Behaviour |
|---|---|
| `featureValue('usage.projects')` | Raw value at the dot-path |
| `canUseFeature('capabilities.api')` | `bool` as-is · array → non-empty · else truthy |
| `hasReachedLimit('usage.projects', $count)` | `null` limit = unlimited |
| `priceFor('monthly'\|'yearly')` | Float price for the cycle |
| `stripePriceId('monthly'\|'yearly')` | Stripe price id for the cycle |
| `isFree()` | `monthly_price <= 0` |

### Feature gating from the billable

```php
$user->currentPlan();                          // ?Plan (from the active subscription's price)
$user->onPlan('pro');                           // bool
$user->canUseFeature('capabilities.api');       // bool
$user->planFeature('usage.projects', 0);        // raw value or default
$user->hasReachedPlanLimit('usage.projects', 5);// bool
```

Gate a route on a feature with the `plan.feature` middleware:

```php
Route::post('/api/tokens', ...)->middleware('plan.feature:capabilities.api');
```

---

## 5. Routes

Either flip `auto_routes` + `enabled` to `true`, or register explicitly in a routes file:

```php
\Happones\Kinetix\Billing\BillingRoutes::register();
```

Registers (under the configured prefix/name): `index`, `subscribe`, `payment-methods.add`, `payment-methods.remove`, `invoices.download`, `cancel`, `resume`, all backed by `BillingController` → `BillingManager`.

---

## 6. BillingManager

The controller is thin; all Cashier orchestration lives in `Happones\Kinetix\Billing\BillingManager`. Use it directly anywhere:

```php
use Happones\Kinetix\Billing\BillingManager;

$manager = BillingManager::for($user);          // or BillingManager::resolve()
$manager->plans();                               // Collection<PlanData>
$manager->subscribe('pro', $paymentMethod, 'monthly');
$manager->cancel();
$manager->resume();
$manager->paymentMethods();                      // camelCase arrays for the UI
$manager->invoices();
$manager->subscriptionData();
```

`subscribe()` is smart: a **free** plan cancels the current subscription (downgrade); a paid plan **swaps** an existing subscription (resuming first if on a grace period) or **creates** a new one (requires a payment method).

### Full method reference

In addition to the methods above, `BillingManager` exposes:

| Method | Returns | Purpose |
|---|---|---|
| `billable()` | `Model` | The resolved billable model instance. |
| `ensureStripeCustomer()` | `void` | Create the billable as a Stripe customer if it isn't one yet (called automatically before issuing intents/adding cards). |
| `setupIntent()` | `mixed` | Create a Stripe SetupIntent for collecting a card (ensures the customer first). |
| `defaultPaymentMethodId()` | `?string` | Id of the billable's default payment method, or `null`. |
| `addPaymentMethod(string $paymentMethod)` | `void` | Attach a payment method; sets it as default if none exists yet. |
| `removePaymentMethod(string $id)` | `void` | Detach the payment method with the given id. |
| `downloadInvoice(string $invoiceId)` | `mixed` | Streamed PDF download response for the invoice (vendor/product set from config). |

---

## 7. Vue components

All components are token-only (shadcn semantic tokens) and take labels via props, so they theme and translate cleanly. Import from your published path (`@/components/kinetix/...`).

| Component | Purpose |
|---|---|
| `KinetixPricingTable` | Responsive grid of plan cards + optional monthly/yearly toggle |
| `KinetixPlanCard` | Single plan: price, highlighted features, capability rows via `featureLabels` |
| `KinetixPaymentMethods` | Saved cards + add-card via Stripe Elements |
| `KinetixSubscriptionStatus` | Status badge + cancel/resume |
| `KinetixInvoicesTable` | Invoice list with per-row download |

<Screenshot name="pricing-table" alt="Pricing table — plan cards" />

<Screenshot name="subscription-status" alt="Subscription status" />

<Screenshot name="invoices-table" alt="Invoices table" />

The `useKinetixBilling(endpoints)` composable centralises the Inertia visits (`subscribe`/`cancel`/`resume`/`addPaymentMethod`/`removePaymentMethod`) and exposes a shared `processing` flag. The scaffolded `Billing/Index.vue` wires it all together — start there.

### Stripe Elements theming (dark/light)

`KinetixPaymentMethods` mounts the Stripe card field through `useKinetixStripe`, which **reads your live shadcn tokens and resolves them to concrete `rgb()` colors** (the Stripe iframe can't inherit CSS). It also watches `<html>` for theme changes and restyles the field on the fly, so the card input matches both light and dark mode automatically. Stripe.js is loaded from the global script tag if present, else lazily from `@stripe/stripe-js`.

---

## 8. The `kinetix:make-billing` command

```bash
php artisan kinetix:make-billing            # scaffold resources/js/pages/Billing/Index.vue
php artisan kinetix:make-billing --seeder   # also scaffold database/seeders/PlanSeeder.php
```

The generated page is a complete, working example wiring every component — customise the `featureLabels` map and route URLs to taste.
