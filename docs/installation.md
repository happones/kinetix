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

# Translations → lang/{en,es,fr,pt}/kinetix.php
php artisan vendor:publish --tag=kinetix-translations

# Notification sound → public/vendor/kinetix/
php artisan vendor:publish --tag=kinetix-assets

# Fallback shadcn design tokens — ONLY if your app is not a shadcn-vue starter kit
php artisan vendor:publish --tag=kinetix-styles
```

::: tip Upgrading
The published files are copies. After a package upgrade, re-publish with `--force`
(e.g. `php artisan vendor:publish --tag=kinetix-components --force`) and review the
[changelog](https://github.com/happones/kinetix/blob/main/CHANGELOG.md) for entries
marked **(published)**.
:::

## 3. Compile translations for Vue

Kinetix ships its UI strings as PHP translation files and compiles them to
TypeScript for the Vue components via
[`happones/laravel-vue-i18n-generator`](https://github.com/happones/laravel-vue-i18n-generator):

```bash
php artisan vue-i18n:generate
```

Run this again whenever you publish translations or add a locale.

## 4. Configure Vue i18n

Since Kinetix uses `vue-i18n` for UI localization, you must install it and register the translation messages generated in the previous step.

### 4.1 Install the dependency

Install `vue-i18n` version 11 using your preferred package manager:

::: code-group
```bash [npm]
npm install vue-i18n@11
```
```bash [pnpm]
pnpm add vue-i18n@11
```
```bash [yarn]
yarn add vue-i18n@11
```
:::

### 4.2 Register in your entry file

In your main Inertia entry file (typically `resources/js/app.ts` or `resources/js/app.js`), register `vue-i18n` using the `withApp` method. This allows you to resolve the locale dynamically from the server-side page props:

```typescript
import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import { createI18n } from 'vue-i18n'
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
  },
})
```

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
    'teams' => env('KINETIX_TEAMS', false),

    'route_prefix' => env('KINETIX_ROUTE_PREFIX', '_kinetix'),
    'middleware'   => ['web', 'auth'],
];
```

| Variable | Default | Description |
|---|---|---|
| `KINETIX_DATABASE_NOTIFICATIONS` | `false` | Persist notifications to the database |
| `KINETIX_NOTIFICATIONS_BROADCAST` | `false` | Broadcast notifications in real time |
| `KINETIX_FILESYSTEM_DISK` | `public` | Disk for uploads / exports / imports / images |
| `KINETIX_TEAMS` | `false` | Scope routes/queries to the current team |
| `KINETIX_ROUTE_PREFIX` | `_kinetix` | Prefix for internal API routes |

## Next steps

- [**Resources**](/resources) — scaffold a full CRUD with one command.
- [**Tables**](/tables) · [**Forms**](/forms) · [**Infolists**](/infolists) · [**Actions**](/actions)
- [**Import & Export**](/import-export) · [**Relation Managers**](/relation-managers)
- [**Notifications**](/notifications) · [**Widgets**](/widgets) · [**Billing**](/billing)
