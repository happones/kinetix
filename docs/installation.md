# Getting Started

Kinetix is a UI toolkit for **Laravel + Vue 3 + Inertia.js** apps built on the
shadcn-vue starter-kit stack. This guide gets it installed and mounted; then head
to any feature in the sidebar.

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.3` |
| Laravel | `^11` \| `^12` \| `^13` |
| `inertiajs/inertia-laravel` | `^2` \| `^3` |
| Vue | `^3.5` |
| `@inertiajs/vue3` | `^3.0` |
| Pinia | `^2.3` *(state management — required)* |
| `vue-i18n` | `^11.0` |
| `vue-sonner` | `^2.0` *(toasts)* |
| `@lucide/vue` | `^1.0` *(icons)* |
| `@laravel/echo-vue` | `^2.3` *(only for broadcasting)* |
| `@tanstack/vue-table` | `^8.0` *(only for client-side tables — `->clientSide()`)* |
| `@tanstack/vue-virtual` | `^3.0` *(only for long-list virtualization — Comments / Kanban)* |
| shadcn-vue / Reka UI | any *(`components.json` present)* |

## 1. Install the package

```bash
composer require happones/kinetix
```

The service provider is auto-discovered — no manual registration needed.

## 2. Publish the assets

```bash
# Config → config/kinetix.php
php artisan vendor:publish --tag=kinetix-config

# Vue components, stores & TypeScript types → resources/js/
php artisan vendor:publish --tag=kinetix-components

# Translations → lang/{locale}/kinetix.php (en, es, fr, pt, zh, ja, ru)
# English-only app? Select what gets published in config/kinetix.php:
#   'translations' => ['locales' => ['en']],        // or KINETIX_TRANSLATION_LOCALES=en
php artisan vendor:publish --tag=kinetix-translations

# Notification sound → public/vendor/kinetix/
php artisan vendor:publish --tag=kinetix-assets

# Fallback shadcn design tokens — ONLY if your app is not a shadcn-vue starter kit
php artisan vendor:publish --tag=kinetix-styles
```

::: tip Upgrading — automatic
`kinetix:install` registers `@php artisan kinetix:upgrade` in your composer.json's
`post-autoload-dump` (the same pattern as Filament's `filament:upgrade`), so every
`composer install`/`update` re-publishes the volatile published assets —
**components** (+ composables, stores, TS types) and **translations** (recompiling
the Vue i18n bundle when `laravel-vue-i18n-generator` is installed). It only
refreshes targets you have already published, and skips apps that never adopted
them.

Because the hook **overwrites** the published copies, treat them as
vendor-managed: customize via wrappers, slots, props and config — not by editing
the published files. If you *do* maintain local edits, remove the hook from
composer.json and re-publish manually with `--force`, reviewing the
[changelog](https://github.com/happones/kinetix/blob/main/CHANGELOG.md) entries
marked **(published)**.
:::

## 3. Compile translations for Vue

Kinetix ships its UI strings as PHP translation files and compiles them to
TypeScript for the Vue components via
[`happones/laravel-vue-i18n-generator`](https://github.com/happones/laravel-vue-i18n-generator).
This is a **separate, optional package** — Kinetix does not depend on it, so
install it first to get the `vue-i18n:generate` command:

```bash
composer require happones/laravel-vue-i18n-generator
php artisan vue-i18n:generate
```

> Already using another vue-i18n toolchain? Skip this — just make sure the
> `kinetix` namespace strings end up where your Vue i18n setup loads them.

Run this again whenever you publish translations or add a locale.

## 4. Run the installer

Kinetix's published Vue components import a few npm packages. The installer adds
them to your `package.json` and installs them, creates a Pinia store, and registers
Pinia + Vue i18n in your Inertia entry file (`app.ts` / `app.js`):

```bash
php artisan kinetix:install

# add chart/widget deps (@unovis/vue, @unovis/ts):
php artisan kinetix:install --charts

# add client-side table + list virtualization deps
# (@tanstack/vue-table, @tanstack/vue-virtual):
php artisan kinetix:install --tanstack

# add real-time notification deps (@laravel/echo-vue):
php artisan kinetix:install --broadcasting

# scaffold a dedicated App\Providers\KinetixServiceProvider:
php artisan kinetix:install --provider
```

It installs these **core** runtime dependencies (`vue` and `@inertiajs/vue3` are
assumed from your starter kit): `pinia`, `vue-i18n`, `reka-ui`,
`@internationalized/date`, `@lucide/vue`, `vue-sonner`. The `--charts`,
`--tanstack` and `--broadcasting` flags add the optional, feature-specific
packages (`--tanstack` covers `@tanstack/vue-table` for `->clientSide()` tables
and `@tanstack/vue-virtual` for the long-list virtualization in Comments and
Kanban).

> If you see a Vite error like *Failed to resolve import "@internationalized/date"*,
> a required dependency is missing — run `php artisan kinetix:install` (or install
> the package listed above manually).

### A dedicated service provider (recommended)

Kinetix registration (feature permissions, module content, gates) grows over
time. Rather than piling it into `AppServiceProvider`, keep it in a dedicated
provider — the Filament pattern. `--provider` scaffolds
`app/Providers/KinetixServiceProvider.php` and registers it in
`bootstrap/providers.php` (idempotent — safe to re-run):

```bash
php artisan kinetix:install --provider
```

Resources under `app/Kinetix/Resources` are auto-discovered (see
[Permissions](./permissions.md)), so the provider only holds your non-resource
features and module content. Keep each module's content in its own small
"registrar" class (a class that just declares/returns its content) and call it
from the provider's `boot()` — e.g. `WebhookEvents::register()`,
`OnboardingSteps::register()`. This keeps `AppServiceProvider` limited to
framework-level defaults.

::: details Manual Installation & Configuration

If you prefer to configure everything manually:

### 4.1 Install dependencies

Install the core runtime dependencies (add `@unovis/vue @unovis/ts` for charts,
`@tanstack/vue-table @tanstack/vue-virtual` for client-side tables + long-list
virtualization, and `@laravel/echo-vue` for broadcasting):

::: code-group
```bash [npm]
npm install pinia vue-i18n@11 reka-ui @internationalized/date @lucide/vue vue-sonner
```
```bash [pnpm]
pnpm add pinia vue-i18n@11 reka-ui @internationalized/date @lucide/vue vue-sonner
```
```bash [yarn]
yarn add pinia vue-i18n@11 reka-ui @internationalized/date @lucide/vue vue-sonner
```
:::

### 4.2 Initialize Pinia store

Create a `stores/index.ts` (or `index.js`) file inside `resources/js/`:

```typescript
import { createPinia } from 'pinia'

const pinia = createPinia()

export default pinia
```

### 4.3 Register in your entry file

In your main Inertia entry file (typically `resources/js/app.ts` or `resources/js/app.js`), register `vue-i18n` and `pinia` using the `withApp` method:

```typescript
import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import { createI18n } from 'vue-i18n'
import pinia from '@/stores'
// Import compiled translation messages
import messages from './vue-i18n-locales'

createInertiaApp({
  resolve: name => {
    // ...
  },
  setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) })
      .use(plugin)
      .mount(el)
  },
  withApp(app, { page }) {
    const i18n = createI18n({
      legacy: false, // Required for Composition API
      locale: page.props.locale as string | undefined, // Load dynamically from Inertia page props
      fallbackLocale: 'en',
      messages,
    })

    app.use(i18n)
    app.use(pinia)
  },
})
```
:::

## 5. Mount the global components

Add these once near the root of your Inertia layout so toasts, notifications and
modals work app-wide:

```vue
<script setup lang="ts">
import KinetixNotifications from "@/components/kinetix/KinetixNotifications.vue";
import KinetixToaster from "@/components/kinetix/KinetixToaster.vue";
import KinetixImportModal from "@/components/kinetix/KinetixImportModal.vue";
import KinetixFilePreview from "@/components/kinetix/KinetixFilePreview.vue";
</script>

<template>
  <!-- ...your layout... -->

  <!-- bell-icon dropdown; place it in your header -->
  <KinetixNotifications />

  <!-- mount ONCE; replace any raw vue-sonner <Toaster> -->
  <KinetixToaster position="top-right" />

  <!-- enable Import actions and file/image previews -->
  <KinetixImportModal />
  <KinetixFilePreview />
</template>
```

::: warning One toaster only
`vue-sonner` uses a single global queue, so every mounted `<Toaster>` renders every
toast. Use **`<KinetixToaster />`** (token-themed, dark-mode-safe) and remove any
other `<Toaster>` your starter kit mounts. See [Notifications](/notifications).
:::

## 6. Build

```bash
npm run build   # or: npm run dev
```

If you ever see *"Unable to locate file in Vite manifest"*, run the build again.

## Theming

Kinetix styles everything with shadcn's **semantic design tokens**
(`bg-background`, `text-foreground`, `bg-primary`, `border-input`, …) and builds
interactive widgets on [Reka UI](https://reka-ui.com/) — the headless library
shadcn-vue itself wraps. It does **not** import your `@/components/ui/*` files, so
it can't break your build; it reuses the same token contract instead.

In a shadcn-vue starter kit the CSS variables already exist. Otherwise publish the
fallback tokens (`--tag=kinetix-styles`) and import `resources/css/kinetix.css`.

### Status tokens (`success` · `warning` · `info`)

shadcn ships `destructive` but no success/warning/info colors, so Kinetix adds
three themeable tokens used by badges, stat chips, modals, notifications and action
colors. If your app defines its own tokens but not these three, add them so
Tailwind generates the `*-success` / `*-warning` / `*-info` utilities:

```css
:root {
  --success: 142 76% 36%;  --success-foreground: 0 0% 100%;
  --warning: 26 90% 37%;   --warning-foreground: 0 0% 100%;
  --info:    200 98% 39%;  --info-foreground: 0 0% 100%;
}
.dark {
  --success: 142 69% 58%;  --warning: 43 96% 56%;  --info: 198 93% 60%;
}
```

`danger` maps to the built-in `destructive` token.

## Configuration

After publishing, edit `config/kinetix.php`. The most relevant keys:

```php
return [
    'notifications' => [
        'database' => env('KINETIX_DATABASE_NOTIFICATIONS', false), // persist in DB
        'limit'    => env('KINETIX_NOTIFICATIONS_LIMIT', 15),
        'sound'    => [
            'enabled' => env('KINETIX_NOTIFICATIONS_SOUND', true),
            'path'    => env('KINETIX_NOTIFICATIONS_SOUND_PATH', '/vendor/kinetix/notification.wav'),
        ],
        'broadcast' => env('KINETIX_NOTIFICATIONS_BROADCAST', false), // real-time
    ],

    // Real-time WebSocket config (Laravel Echo). See the Notifications guide.
    'broadcasting' => [ /* 'echo' => [ ... ] */ ],

    // Global filesystem disk for uploads, image columns, exports & imports.
    'filesystem' => ['disk' => env('KINETIX_FILESYSTEM_DISK', 'public')],

    // Scope internal routes/queries to the current team (e.g. {team}/_kinetix).
    'teams' => env('KINETIX_TEAMS_ENABLED', false),

    'route_prefix' => env('KINETIX_ROUTE_PREFIX', '_kinetix'),
    'middleware'   => ['web', 'auth'],
];
```

| Variable | Default | Description |
|---|---|---|
| `KINETIX_DATABASE_NOTIFICATIONS` | `false` | Persist notifications to the database |
| `KINETIX_NOTIFICATIONS_BROADCAST` | `false` | Broadcast notifications in real time |
| `KINETIX_FILESYSTEM_DISK` | `public` | Disk for uploads / exports / imports / images |
| `KINETIX_TEAMS_ENABLED` | `false` | Scope routes/queries to the current team |
| `KINETIX_ROUTE_PREFIX` | `_kinetix` | Prefix for internal API routes |

### Team scoping: one switch, per-module overrides

`kinetix.teams` is the single switch for team scoping — besides prefixing
Kinetix's internal routes with `{current_team}/`, it is the **default for every
module's own `teams` flag** (permissions, membership, settings, webhooks,
onboarding, wizards, features, activity, billing). Each module flag is
tri-state:

- `null` (the default) — inherit `kinetix.teams`;
- `true` / `false` — explicit override for that module.

```php
'teams' => true,                       // everything team-scoped…
'billing' => ['teams' => false],       // …except billing (personal subscriptions)
```

> Flipping a module's scope on a live app changes which rows its queries see
> (`team_id` filters) — plan a data migration if you change it after launch.

### The `{current_team}` segment: route keys & membership

With teams on, Kinetix's routes gain a leading `{current_team}` parameter. The
segment carries the team's **route key** (`Team::getRouteKeyName()` — often a
slug or uuid, not the id). Kinetix resolves it via
`KinetixTeams::currentTeamKey()`:

- a **bound model** (if your app registered a binding) → its primary key;
- a scalar segment → looked up **through the authenticated user's teams
  relation** (`kinetix.team_switcher.teams_relation`, default `teams`) by the
  route key name. This doubles as the **membership check**: a segment that is
  not one of the user's teams responds `404`;
- if the user model has no teams relation, the raw segment is trusted as the
  key (id-routed teams) and **membership enforcement is your responsibility**
  (e.g. a middleware on your side).

## Next steps

- [**Resources**](/resources) — scaffold a full CRUD with one command.
- [**Tables**](/tables) · [**Forms**](/forms) · [**Infolists**](/infolists) · [**Actions**](/actions)
- [**Import & Export**](/import-export) · [**Relation Managers**](/relation-managers)
- [**Notifications**](/notifications) · [**Widgets**](/widgets) · [**Billing**](/billing)
