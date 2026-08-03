<p align="center">
  <img src="https://raw.githubusercontent.com/happones/kinetix/main/art/logo.png" alt="Kinetix — The Hybrid-Driven Framework for Laravel & Vue" width="360" />
</p>

<p align="center">
  <b>The hybrid framework for Laravel + Vue 3 + Inertia.js.</b><br>
  One package spanning backend and frontend — fluent PHP APIs that render polished, real-time shadcn/Vue components, with full i18n.
</p>

<p align="center">
  <a href="https://github.com/happones/kinetix/actions/workflows/ci.yml"><img src="https://github.com/happones/kinetix/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://packagist.org/packages/happones/kinetix"><img src="https://img.shields.io/packagist/v/happones/kinetix" alt="Latest Stable Version"></a>
  <a href="https://packagist.org/packages/happones/kinetix"><img src="https://img.shields.io/packagist/dt/happones/kinetix" alt="Total Downloads"></a>
  <a href="LICENSE"><img src="https://img.shields.io/packagist/l/happones/kinetix" alt="License"></a>
  <a href="https://packagist.org/packages/happones/kinetix"><img src="https://img.shields.io/packagist/php-v/happones/kinetix" alt="PHP Version"></a>
  <a href="#-ai-ready"><img src="https://img.shields.io/badge/AI--ready-agent%20skills%20%2B%20Laravel%20Boost-8b5cf6" alt="AI-ready"></a>
</p>

<p align="center">
  <a href="https://happones.github.io/kinetix/"><strong>📖 Documentation</strong></a>
</p>

---

> [!WARNING]
> **Pre-1.0 — under active development.** Kinetix does not yet have a release
> candidate for v1: APIs, published components and defaults may still change
> between minor versions, so pin your version and review the
> [CHANGELOG](CHANGELOG.md) before upgrading. A **beta** and a **release
> candidate** on the road to **v1.0** will be announced shortly — watch the
> repo to stay posted.

---

## What is Kinetix?

Kinetix is **the hybrid framework for Laravel + Vue 3 + Inertia.js** — a single
package that spans both sides of your app. You write fluent PHP (CRUD resources,
tables, forms, billing, permissions, notifications, and much more) and get a
polished, reactive shadcn-styled Vue UI, wired together over Inertia. 50+ opt-in
modules.

It's **"hybrid"** because it lives in both worlds at once: backend builders,
DTOs and query pipelines on the PHP side; real components on the Vue side — not a
frontend-only UI kit, not a backend-only package. Think Filament's developer
experience, built from the ground up for the **Inertia + Vue** stack.

> **Complement, not replacement.** Kinetix is designed to sit *alongside* the
> official Laravel starter kit — the starter kit owns auth, 2FA, appearance and
> basic account management; Kinetix owns the application layer on top. It is
> **not** a fork or clone of any existing package.

Every module is **opt-in** (off by default, one config switch), and third-party
packages are **optional** — each feature detects the package at runtime and
falls back to a fully working built-in driver when it's absent.

---

## 🤖 AI-ready

Kinetix is built to be **developed with coding agents, not just by hand** —
whether that's Claude Code, Codex or any agent wired into
[Laravel Boost](https://github.com/laravel/boost):

- **An agent skill per module (46 and counting).** Every feature ships its own
  skill (`kinetix-tables`, `kinetix-forms`, `kinetix-permissions`, …) teaching
  the agent the module's fluent API, conventions and pitfalls — so it writes
  Kinetix code the way the docs intend, without guessing.
- **Installed automatically.** `php artisan kinetix:install` publishes the
  skills into your project (default `.claude/skills`; point
  `KINETIX_SKILLS_PATH` at `.agents/skills` or wherever your agent looks), and
  `kinetix:upgrade` keeps them fresh on every release. Skills only load from
  your project, never from `vendor/`.
- **Laravel Boost, first-class.** A dedicated `kinetix-boost` skill wires
  agents into Boost's tooling — `search-docs`, `database-schema`,
  `database-query`, Pint/PHPStan — so they inspect real project state instead
  of hallucinating it.
- **Ground truth everywhere.** Every module has a dedicated docs guide,
  translations and a test suite the agent can run — the same segmented,
  per-feature documentation humans read is what keeps agents on rails.

The result: point your agent at "add KPI cards above the books table" and it
has the skill, the docs and the tests to do it right on the first pass.

---

## Features

Each module ships with a dedicated `docs/<feature>.md` guide, translations
(en/es/fr/pt) and tests. See [`ROADMAP.md`](ROADMAP.md) for the full module map.

- **Data display** — [Resources](docs/resources.md) (CRUD scaffolding),
  [Tables](docs/tables.md) (filters, sort incl. relationships, inline edit,
  client-side/TanStack mode), [Infolists](docs/infolists.md),
  [Relation Managers](docs/relation-managers.md), [Saved Views](docs/saved-views.md),
  [Widgets](docs/widgets.md), [Kanban](docs/kanban.md), [Calendar](docs/calendar.md).
- **Forms & input** — [Forms](docs/forms.md) (30+ fields, layouts, validation via
  fluent rules / FormRequest / live Precognition), [Wizard](docs/wizard.md),
  [Table Repeater](docs/table-repeater.md).
- **Actions & navigation** — [Actions](docs/actions.md), [Spotlight](docs/spotlight.md)
  (`Cmd+K`), [Keyboard Shortcuts](docs/keyboard-shortcuts.md),
  [Breadcrumbs](docs/breadcrumbs.md), [Period Filter](docs/period-filter.md),
  [Onboarding](docs/onboarding.md).
- **Notifications & collaboration** — [Notifications](docs/notifications.md)
  (local/database/broadcast), [Notification Preferences](docs/notification-preferences.md),
  [Comments](docs/comments.md), [Tags](docs/tags.md),
  [Announcements](docs/announcements.md), [Presence](docs/presence.md),
  [Mail Templates](docs/mail-templates.md).
- **Import / export / documents** — [Import & Export](docs/import-export.md),
  [Reports](docs/reports.md), [Reports Center](docs/reports-center.md),
  [PDF Templates](docs/pdf-templates.md), [Media Library](docs/media-library.md).
- **SaaS platform** — [Billing](docs/billing.md), [Membership](docs/membership.md),
  [Team Switcher](docs/team-switcher.md), [Permissions](docs/permissions.md),
  [Feature Flags](docs/feature-flags.md), [Impersonation](docs/impersonation.md),
  [Activity Log](docs/activity.md), [Webhooks](docs/webhooks.md),
  [Developer Tokens](docs/tokens.md), [Connected Accounts](docs/connected-accounts.md),
  [Integration Logs](docs/integration-logs.md), [Health](docs/health.md),
  [Queue](docs/queue.md), [Sessions](docs/sessions.md).
- **Security & compliance** — [GDPR](docs/gdpr.md),
  [Confidential Fields](docs/confidential.md), [Accessibility](docs/accessibility.md),
  [Cookie Consent](docs/cookie-consent.md).
- **Configuration & localization** — [Settings](docs/settings.md),
  [Locale](docs/locale.md), [Timezone Picker](docs/timezone-picker.md).

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | ^8.3 |
| Laravel | ^11 \| ^12 \| ^13 |
| `inertiajs/inertia-laravel` | ^2 \| ^3 |
| Vue | ^3.5 |
| `@inertiajs/vue3` | ^3.0 |
| `@laravel/echo-vue` | ^2.3 *(for broadcasting)* |
| `vue-i18n` | ^11.0 |
| `vue-sonner` | ^2.0 |
| `@lucide/vue` | ^1.0 |
| Pinia | ^2.3 *(required for state management)* |
| Shadcn / Reka UI | any *(components.json required)* |

---

## Installation

### 1. Require the package

```bash
composer require happones/kinetix
```

The service provider is auto-discovered by Laravel.

### 2. Publish assets

```bash
# Config file → config/kinetix.php
php artisan vendor:publish --tag=kinetix-config

# Vue components, stores, and types → resources/js/
php artisan vendor:publish --tag=kinetix-components

# Translations → lang/{en,es,fr,pt}/kinetix.php
php artisan vendor:publish --tag=kinetix-translations

# Audio assets → public/vendor/kinetix/
php artisan vendor:publish --tag=kinetix-assets

# Fallback design tokens — only if your app is NOT a shadcn-vue starter kit
php artisan vendor:publish --tag=kinetix-styles

# Per-module agent skills → .claude/skills/kinetix-* (kinetix:install does this
# for you; coding agents only read the project, never vendor/)
php artisan vendor:publish --tag=kinetix-skills
```

> **Where Kinetix's endpoints live.** Every module registers its own endpoints
> under `{current_team}/{kinetix.route_prefix}/…` and the published components
> call them themselves — your app registers only the Inertia page routes. Run
> `php artisan kinetix:routes` to see the resolved URIs before writing an endpoint
> of your own.

> **Something not working?** `php artisan kinetix:doctor` reports the
> misconfigurations that otherwise fail silently — half-enabled team scoping,
> teamless roles, a missing `attach_member`, duplicated i18n bundles, closures in
> config that break `config:cache`, and published files carrying local edits that
> the next `composer install` will discard. It exits non-zero on errors, so it
> doubles as a deploy gate.

### Theming (shadcn tokens)

Kinetix is built for the **Vue + shadcn-vue** starter-kit stack. Its components:

- Style with shadcn's **semantic design tokens** (`bg-background`, `text-foreground`, `bg-primary`, `border-input`, `ring-ring`, `bg-muted`, …), so they inherit your app's theme, palette, radius and dark mode automatically.
- Build interactive primitives on **[Reka UI](https://reka-ui.com/)** (the headless library shadcn-vue itself wraps) for accessible checkboxes, switches, calendars, etc.

Kinetix does **not** import your copied `@/components/ui/*` files (those vary per app and would break builds); instead it reuses the same *foundation* — Reka UI + the shadcn token contract. In a shadcn-vue starter kit the CSS variables already exist; otherwise publish the fallback tokens with `--tag=kinetix-styles` and import `resources/css/kinetix.css`.

#### Status tokens (`success` · `warning` · `info`)

shadcn ships `destructive` but has no success/warning/info colors, so Kinetix adds three themeable status tokens — used by badges, stat chips, confirmation modals, notifications and action colors. They're defined in the fallback `kinetix.css` (light + dark) and exposed as utilities (`bg-success`, `text-warning`, `border-info/20`, `text-success-foreground`, …). Re-skin them by overriding the variables in your own theme:

```css
:root {
  --success: 142 76% 36%;  --success-foreground: 0 0% 100%;
  --warning: 26 90% 37%;   --warning-foreground: 0 0% 100%;
  --info:    200 98% 39%;  --info-foreground: 0 0% 100%;
}
.dark {
  --success: 142 69% 58%;  --warning: 43 96% 56%;  --info: 198 93% 60%;
  /* …foregrounds… */
}
```

> If your app defines its own design tokens but not these three, add the six lines above so Tailwind generates the `*-success/warning/info` utilities. The `danger` status maps to the built-in `destructive` token.

#### shadcn-vue parity primitives

For the elements that aren't Reka UI primitives (Card, Button, Badge, Input), Kinetix ships its own internal building blocks that mirror the official [shadcn-vue **new-york-v4**](https://github.com/unovue/shadcn-vue/tree/dev/apps/v4/registry/new-york-v4/ui) class strings **exactly**, so they match the registry design without importing your per-app `@/components/ui/*`:

- **Card family** — `components/primitives/{Card,CardHeader,CardTitle,CardDescription,CardAction,CardContent,CardFooter}.vue` (v4 structure: `flex flex-col gap-6 py-6` card + `px-6` sections).
- **ScrollArea / ScrollBar** — `components/primitives/{ScrollArea,ScrollBar}.vue` (Reka `ScrollArea*`) for custom scroll regions (e.g. the DateTimePicker time columns).
- **Variant helpers** — `composables/useShadcnVariants.ts` → `buttonVariants({variant,size})`, `badgeVariants({variant})`, `inputClass` (literal class strings, JIT-safe).

```vue
<script setup>
import Card from "@/components/kinetix/primitives/Card.vue";
import CardHeader from "@/components/kinetix/primitives/CardHeader.vue";
import { buttonVariants } from "@/composables/useShadcnVariants";
</script>
```

### 3. Compile translations for Vue

Kinetix uses [`happones/laravel-vue-i18n-generator`](https://github.com/happones/laravel-vue-i18n-generator) to compile PHP translation files into TypeScript for use in Vue components.

```bash
php artisan vue-i18n:generate
```

---

## Configuration

After publishing, edit `config/kinetix.php`:

```php
return [

    'brand' => [
        'name'    => env('APP_NAME', 'Kinetix'),
        'logo'    => env('KINETIX_BRAND_LOGO', null),
        'favicon' => env('KINETIX_BRAND_FAVICON', null),
    ],

    'assets' => [
        'path'  => env('KINETIX_ASSETS_PATH', 'vendor/kinetix'),
        'cache' => env('KINETIX_ASSETS_CACHE', true),
    ],

    'notifications' => [
        // Set to true to persist notifications in the database
        'database' => env('KINETIX_DATABASE_NOTIFICATIONS', false),

        // Max unread notifications loaded per request (database mode)
        'limit' => env('KINETIX_NOTIFICATIONS_LIMIT', 15),

        'sound' => [
            'enabled' => env('KINETIX_NOTIFICATIONS_SOUND', true),
            'path'    => env('KINETIX_NOTIFICATIONS_SOUND_PATH', '/vendor/kinetix/notification.wav'),
        ],
    ],

    // Uncomment to enable real-time WebSocket notifications via Laravel Echo
    'broadcasting' => [
        // 'echo' => [
        //     'broadcaster'       => 'reverb',
        //     'key'               => env('VITE_REVERB_APP_KEY'),
        //     'wsHost'            => env('VITE_REVERB_HOST', '127.0.0.1'),
        //     'wsPort'            => env('VITE_REVERB_PORT', 8080),
        //     'wssPort'           => env('VITE_REVERB_PORT', 443),
        //     'forceTLS'          => env('VITE_REVERB_SCHEME', 'https') === 'https',
        //     'enabledTransports' => ['ws', 'wss'],
        // ],
    ],

    // Internal API route prefix — change if it conflicts with your routes
    'route_prefix' => env('KINETIX_ROUTE_PREFIX', '_kinetix'),

    // Middleware applied to all internal Kinetix routes
    'middleware' => ['web', 'auth'],

];
```

### Environment Variables

| Variable | Default | Description |
|---|---|---|
| `KINETIX_DATABASE_NOTIFICATIONS` | `false` | Dry-run / persist notifications to DB |
| `KINETIX_NOTIFICATIONS_LIMIT` | `15` | Max notifications loaded per request |
| `KINETIX_NOTIFICATIONS_SOUND` | `true` | Play sound on new notification |
| `KINETIX_NOTIFICATIONS_SOUND_PATH` | `/vendor/kinetix/notification.wav` | Path to the audio file |
| `KINETIX_ROUTE_PREFIX` | `_kinetix` | Prefix for internal API routes |

---

## Notifications

Kinetix's notification system lets you build and dispatch beautiful, interactive notifications using a fluent PHP API. They appear instantly as **Sonner toasts** and are collected in a **bell-icon dropdown** in the app header.

### Basic Usage

```php
use Happones\Kinetix\Notifications\Notification;

Notification::make()
    ->title('Profile updated')
    ->description('Your changes have been saved successfully.')
    ->success()
    ->send();
```

### Status Levels

```php
Notification::make()->title('Done!')->success()->send();
Notification::make()->title('Heads up')->warning()->send();
Notification::make()->title('Error occurred')->danger()->send();
Notification::make()->title('FYI')->info()->send();  // default
```

### Duration Control

```php
// Custom duration in milliseconds
Notification::make()->title('Quick!')->duration(2000)->send();

// Helper: set duration in seconds
Notification::make()->title('5 second notice')->seconds(5)->send();

// Never auto-close
Notification::make()->title('Important')->persistent()->send();
```

### Database Persistence

Set `KINETIX_DATABASE_NOTIFICATIONS=true` in your `.env`, and route using the fluent `to($user)` method or directly via `sendToDatabase($user)`:

```php
// Option A: Set recipient fluently (Recommended)
Notification::make()
    ->to($user)
    ->title('New assignment')
    ->description('Ticket #4562 has been assigned to you.')
    ->info()
    ->send(); // Automatically routes to database if configured

// Option B: Pass recipient directly
Notification::make()
    ->title('New assignment')
    ->description('Ticket #4562 has been assigned to you.')
    ->info()
    ->sendToDatabase($user);
```

### Real-Time Broadcasting

Push a notification instantly via WebSockets (saves to DB **and** broadcasts). You can chain `to($user)` and call `broadcast()` or pass the user directly to `broadcast($user)`:

```php
// Option A: Set recipient fluently (Recommended)
Notification::make()
    ->to($user)
    ->title('Server alert')
    ->description('CPU usage exceeded 85%.')
    ->danger()
    ->broadcast();

// Option B: Pass recipient directly
Notification::make()
    ->title('Server alert')
    ->description('CPU usage exceeded 85%.')
    ->danger()
    ->broadcast($user);
```
```

---

## Actions

Attach interactive buttons or links to any notification:

```php
use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Notifications\Notification;

Notification::make()
    ->title('Backup completed')
    ->success()
    ->actions([
        Action::make('view')
            ->label('View Report')
            ->url('/reports/backup')
            ->button()
            ->color('primary')
            ->markAsRead()
            ->close(),

        Action::make('dismiss')
            ->label('Dismiss')
            ->link()
            ->color('gray')
            ->close(),
    ])
    ->send();
```

### Action API Reference

| Method | Description |
|---|---|
| `::make(string $name)` | Create a new action |
| `->label(string $label)` | Display text |
| `->icon(string $icon, string $position = 'before')` | Lucide icon name (e.g. `'trash'`, `'check'`) |
| `->url(string $url, bool $newTab = false)` | Navigate to a URL on click |
| `->inertiaVisit(string $url, array $options = [])` | SPA navigation via `router.visit()` |
| `->dispatch(string $event, array $data = [])` | Fire a `kinetix:{event}` browser CustomEvent |
| `->button()` | Render as a filled button *(default)* |
| `->link()` | Render as a text link |
| `->color(string $color)` | `primary` · `success` · `warning` · `danger` · `gray` |
| `->size(string $size)` | `xs` · `sm` · `md` · `lg` |
| `->close()` | Dismiss notification on click |
| `->markAsRead()` | Mark parent notification as read on click |
| `->markAsUnread()` | Mark parent notification as unread on click |

### Listening for Dispatched Events

When an action uses `->dispatch('my-event', ['id' => 42])`, listen in Vue:

```ts
window.addEventListener('kinetix:my-event', (e: Event) => {
    const { id } = (e as CustomEvent).detail; // { id: 42 }
});
```

---

## Custom Notification Classes

Generate reusable, pre-configured notification classes:

```bash
php artisan kinetix:make-notification BackupSuccessNotification
```

This creates `app/Kinetix/Notifications/BackupSuccessNotification.php`:

```php
namespace App\Kinetix\Notifications;

use Happones\Kinetix\Notifications\Notification;

class BackupSuccessNotification extends Notification
{
    public function __construct()
    {
        parent::__construct();

        $this->title('Backup completed successfully')
             ->description('Your latest backup is ready.')
             ->success()
             ->duration(5000);
    }
}
```

Use it anywhere:

```php
BackupSuccessNotification::make()->send();
BackupSuccessNotification::make()->sendToDatabase($user);
```

---

## Frontend Integration

### 1. Add the component to your layout

After publishing, import and place `KinetixNotifications` in your app header. The component reads `page.props.auth.user` from Inertia automatically.

**`resources/js/components/AppSidebarHeader.vue`:**

```vue
<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import KinetixNotifications from '@/components/kinetix/KinetixNotifications.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItem[] }>(), { breadcrumbs: () => [] });
</script>

<template>
    <header class="flex h-16 shrink-0 items-center gap-2 border-b px-6">
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <Breadcrumbs :breadcrumbs="breadcrumbs" />
        </div>

        <div class="ml-auto flex items-center gap-2">
            <KinetixNotifications />
        </div>
    </header>
</template>
```

### 2. Component Props

| Prop | Type | Default | Description |
|---|---|---|---|
| `channelModel` | `string` | `'App.Models.User'` | Echo private channel model prefix. Change if your User model is in a different namespace. |

```vue
<!-- Default -->
<KinetixNotifications />

<!-- Custom model namespace -->
<KinetixNotifications channel-model="App.Models.Admin" />
```

---

## Real-Time Broadcasting Setup

### 1. Uncomment the Echo block in `config/kinetix.php`

```php
'broadcasting' => [
    'echo' => [
        'broadcaster'       => 'reverb',
        'key'               => env('VITE_REVERB_APP_KEY'),
        'wsHost'            => env('VITE_REVERB_HOST', '127.0.0.1'),
        'wsPort'            => env('VITE_REVERB_PORT', 8080),
        'wssPort'           => env('VITE_REVERB_PORT', 443),
        'forceTLS'          => env('VITE_REVERB_SCHEME', 'https') === 'https',
        'enabledTransports' => ['ws', 'wss'],
    ],
],
```

### 2. Install `@laravel/echo-vue`

Already included in the Laravel starter kit. If not present:

```bash
npm install @laravel/echo-vue
```

### 3. Call `configureEcho` in `resources/js/app.ts`

```ts
import { configureEcho } from '@laravel/echo-vue';

// Reverb — defaults are auto-filled from VITE_ env variables
configureEcho({
    broadcaster: 'reverb',
});
```

That's it. `KinetixNotifications` uses `useEchoNotification` internally and auto-connects to the authenticated user's private channel `App.Models.User.{id}`. No extra setup needed in Vue.

### 4. Broadcast from PHP

```php
Notification::make()
    ->title('New message')
    ->info()
    ->broadcast($user);
```

---

## Multilingual Support (i18n)

Kinetix ships with translations for **English**, **Spanish**, **French**, and **Portuguese**.

All strings in the Vue component are driven by `vue-i18n` keys (`t('kinetix.key')`), compiled from PHP array files via `happones/laravel-vue-i18n-generator`.

### Publish & compile

```bash
# Publish PHP translation files into your lang/ directory
php artisan vendor:publish --tag=kinetix-translations --force

# Compile to TypeScript for Vue
php artisan vue-i18n:generate
```

This creates/updates `resources/js/vue-i18n-locales.ts` which is consumed automatically by the component.

### Adding a new locale

1. Create `lang/{locale}/kinetix.php` with the translation keys.
2. Run `php artisan vue-i18n:generate`.
3. Register the locale in your `vue-i18n` setup.

---

## Artisan Commands

### Generators

Scaffold independent, reusable Kinetix classes under `app/Kinetix/`:

| Command | Generates |
|---|---|
| `kinetix:make-resource {Name}` | Full CRUD resource (PHP resource + controller + Vue pages); `--generate`, `--simple`, `--soft-deletes`, `--team` (team-scoped routes/queries; auto-on when `kinetix.teams`) |
| `kinetix:make-action {Name}` | `app/Kinetix/Actions/{Name}` |
| `kinetix:make-table {Name}` | `app/Kinetix/Tables/{Name}` |
| `kinetix:make-form {Name}` | `app/Kinetix/Forms/{Name}` |
| `kinetix:make-infolist {Name}` | `app/Kinetix/Infolists/{Name}` |
| `kinetix:make-importer {Name}` | `app/Kinetix/Importers/{Name}` |
| `kinetix:make-exporter {Name}` | `app/Kinetix/Exporters/{Name}` |
| `kinetix:make-relation-manager {Name} --relationship=posts` | `app/Kinetix/RelationManagers/{Name}` |
| `kinetix:make-notification {Name}` | `app/Kinetix/Notifications/{Name}` |
| `kinetix:make-billing` | Billing page `resources/js/pages/Billing/Index.vue` (`--seeder` adds a `PlanSeeder`) |

All generators accept `--force` to overwrite an existing file.

### `kinetix:make-notification`

Generate a reusable custom notification class:

```bash
php artisan kinetix:make-notification OrderShippedNotification
```

Creates `app/Kinetix/Notifications/OrderShippedNotification.php`.

---

### `kinetix:send-notification`

Send a test notification directly from the terminal (useful during development):

```bash
php artisan kinetix:send-notification "Server Alert" "CPU usage at 90%" --status=warning --duration=5000
```

| Argument / Option | Description |
|---|---|
| `title` | Notification title *(required)* |
| `description` | Body text *(optional)* |
| `--status` | `info` · `success` · `warning` · `danger` *(default: `info`)* |
| `--duration` | Toast duration in ms *(default: `4000`)* |

---

## Billing (optional, Cashier + Stripe)

An optional billing/pricing module that wraps [Laravel Cashier](https://laravel.com/docs/billing) behind Kinetix classes and Vue components — drop in a pricing table, payment-method manager, subscription status, and invoices list by calling components and classes, no bespoke billing code.

```bash
composer require laravel/cashier && php artisan migrate
php artisan vendor:publish --tag=kinetix-billing-migrations && php artisan migrate
php artisan kinetix:make-billing --seeder
```

```php
use Laravel\Cashier\Billable;
use Happones\Kinetix\Billing\Concerns\HasPlan;

class User extends Authenticatable
{
    use Billable, HasPlan;
}

// Feature gating from anywhere
$user->canUseFeature('capabilities.api');
Route::post('/export', ...)->middleware('plan.feature:capabilities.api');
```

- **Plans** with nested JSON `features` resolved by dot-path (`canUseFeature`, `hasReachedLimit`, `priceFor`, `isFree`).
- **`BillingManager`** wraps Cashier (subscribe/swap/cancel/resume, payment methods, invoices). Free plans downgrade; paid plans swap or create.
- **Vue**: `KinetixPricingTable`, `KinetixPlanCard`, `KinetixPaymentMethods`, `KinetixSubscriptionStatus`, `KinetixInvoicesTable` + `useKinetixBilling`. The Stripe Elements card field is styled from your **shadcn tokens** and re-themed automatically in light/dark mode via `useKinetixStripe`.
- Off by default (`KINETIX_BILLING_ENABLED`); Cashier is a suggested dependency.

Full guide: [docs/billing.md](docs/billing.md).

---

## Architecture Overview

```
┌──────────────────────────────────────────────────────────────┐
│  PHP (Laravel)                                               │
│                                                              │
│  Notification::make()                                        │
│    ->title(...)  ->success()  ->actions([...])  ->send()    │
│         │                                                    │
│         ├─ send()          → session flash                   │
│         ├─ sendToDatabase() → notifications table            │
│         └─ broadcast()     → DB + Laravel Echo broadcast     │
└──────────────────────────────────────────────────────────────┘
         │ Inertia shared props (kinetix_notifications)
         ▼
┌──────────────────────────────────────────────────────────────┐
│  Vue (Inertia)                                               │
│                                                              │
│  KinetixNotifications.vue                                    │
│    ├─ watch(page.props.kinetix_notifications) → local/DB     │
│    ├─ useEchoNotification() → real-time broadcast            │
│    ├─ Sonner toasts on new notification                      │
│    ├─ Bell icon with unread badge in header                  │
│    └─ fetch() → /_kinetix/notifications/* (mark read/clear)  │
└──────────────────────────────────────────────────────────────┘
```

### How the three delivery modes work

| Mode | How to enable | How data reaches Vue |
|---|---|---|
| **Local** *(default)* | Always on | Session flash → Inertia shared prop on next request |
| **Database** | `KINETIX_DATABASE_NOTIFICATIONS=true` | `unreadNotifications()` query on every request via shared prop |
| **Broadcast** | Configure Echo + call `->broadcast($user)` | `useEchoNotification` on private user channel, plus DB reload |

---

## Directory Structure

```
kinetix/
├── config/
│   └── kinetix.php                 # Published config (one switch per module)
├── docs/                           # One <feature>.md guide per module (50+)
├── public/vendor/kinetix/          # Published audio/static assets
├── resources/
│   ├── js/
│   │   ├── components/             # Kinetix* Vue components (+ Table/ sub-parts)
│   │   ├── composables/            # useKinetix* composables
│   │   └── types/                  # Generated/handwritten TS types
│   ├── boost/skills/               # kinetix-<feature> Boost skills
│   └── lang/{en,es,fr,pt}/kinetix.php
└── src/
    ├── KinetixServiceProvider.php  # Registers every enabled module
    ├── Data/                       # Spatie Data DTOs serialized to the frontend
    ├── Support/                    # Shared concerns/helpers
    │
    ├── Resources/  Tables/  Forms/  Wizards/  Infolists/  SavedViews/  Widgets/
    ├── Actions/  Spotlight/  Onboarding/
    ├── Notifications/  NotificationPreferences/  Comments/  Tags/
    │   Announcements/  Presence/  Mail/
    ├── Imports/  Exports/  Reports/  ReportsCenter/  Pdf/  Media/  Calendar/  Kanban/
    ├── Billing/  Membership/  Teams/  Permissions/  Features/  Impersonation/
    │   Activity/  Webhooks/  Tokens/  ConnectedAccounts/  Health/  Queue/  Sessions/
    ├── Gdpr/  Confidential/  Accessibility/
    └── Settings/  Locale/  Commands/  Api/
```

> Only the modules you enable in `config/kinetix.php` register their routes,
> components and migrations — the rest stay inert.

---

## Roadmap

Kinetix ships **50+ modules** today — resources, tables, forms, infolists,
billing, permissions, notifications, webhooks, reports, and much more (see the
[Features](#features) section above). The full module map, the optional-package
strategy, and the candidate features next on the table (plan-gating, weekly
business-hours, metered usage) live in [`ROADMAP.md`](ROADMAP.md); per-release
detail is in [`CHANGELOG.md`](CHANGELOG.md).

---

## Testing

Kinetix ships a PHPUnit suite built on `orchestra/testbench` (an in-memory SQLite app — no external services required):

```bash
composer test
# or
vendor/bin/phpunit
```

Tests live in `tests/` (`Happones\Kinetix\Tests\` namespace) — 670+ tests across the module builders (forms, tables, infolists, import/export, reports), the validation paths (fluent, FormRequest, Precognition), and the security boundaries (inline-edit, field authorization, signed tokens, confidential fields).

### Static analysis

Kinetix is analysed with [Larastan](https://github.com/larastan/larastan) (PHPStan for Laravel) at **level 5**:

```bash
composer analyse
# or
vendor/bin/phpstan analyse
```

Configuration lives in `phpstan.neon`. The intentional `::make()` → `new static()` builder pattern and host-app `User` model members (`Notifiable`, optional teams) are documented as ignored; everything else is clean.

### Frontend (Vue) tests

Vue components are tested with [Vitest](https://vitest.dev/) + [`@vue/test-utils`](https://test-utils.vuejs.org/) (happy-dom environment):

```bash
npm install
npm run test:unit        # single run
npm run test:unit:watch  # watch mode
```

Specs live in `tests/js/` (430+ specs for components and composables). The `@` alias resolves to `resources/js` (see `vitest.config.ts`). Components that use `vue-i18n` should be mounted with an i18n instance via `global.plugins`.

---

## Contributing

Contributions, bug reports, and feature requests are welcome. Please open an issue or submit a pull request on GitHub.

---

## License

Kinetix is open-sourced software licensed under the [MIT license](LICENSE).

---

<p align="center">
  Built with ❤️ by <a href="https://github.com/happones">happones</a>
</p>
