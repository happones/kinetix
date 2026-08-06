# Kinetix Notifications

Kinetix provides a beautiful, modern notification system designed specifically for **Vue 3**, **Inertia.js 3**, and **Shadcn**. Build and trigger backend notifications with a fluent PHP API, delivered instantly as toast popups and persisted in a bell-icon dropdown in the application header.

---

## Requirements

- **PHP**: 8.3+
- **Laravel**: 11, 12, or 13+
- **Inertia.js**: v3
- **Vue**: v3
- **Pinia**: Required (for state management)
- **Shadcn / Reka-UI**: Required (includes `vue-sonner` and `@lucide/vue`)
- **i18n**: Compatible with `happones/laravel-vue-i18n-generator`

---

## Installation & Publishing

### 1. Publish the config file

```bash
php artisan vendor:publish --tag=kinetix-config
```

### 2. Publish Vue components

```bash
php artisan vendor:publish --tag=kinetix-components
```

### 3. Publish translations

```bash
php artisan vendor:publish --tag=kinetix-translations --force
```

### 4. Publish public assets (audio, etc.)

```bash
php artisan vendor:publish --tag=kinetix-assets
```

---

## Configuration (`config/kinetix.php`)

```php
return [

    'brand' => [
        'name' => env('APP_NAME', 'Kinetix'),
        'logo' => env('KINETIX_BRAND_LOGO', null),
        'favicon' => env('KINETIX_BRAND_FAVICON', null),
    ],

    'assets' => [
        'path' => env('KINETIX_ASSETS_PATH', 'vendor/kinetix'),
        'cache' => env('KINETIX_ASSETS_CACHE', true),
    ],

    'notifications' => [
        'database'  => env('KINETIX_DATABASE_NOTIFICATIONS', false),
        'broadcast' => env('KINETIX_NOTIFICATIONS_BROADCAST', false),
        'limit'     => env('KINETIX_NOTIFICATIONS_LIMIT', 15),
        'poll'      => env('KINETIX_NOTIFICATIONS_POLL', 30000),
        'teams'     => env('KINETIX_NOTIFICATIONS_TEAMS'),
        'sound'    => [
            'enabled' => env('KINETIX_NOTIFICATIONS_SOUND', true),
            'path'    => env('KINETIX_NOTIFICATIONS_SOUND_PATH', '/vendor/kinetix/notification.wav'),
        ],
    ],

    // Uncomment to enable real-time broadcasting via Laravel Echo
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

    'route_prefix' => env('KINETIX_ROUTE_PREFIX', '_kinetix'),

    'middleware' => ['web', 'auth'],

];
```

### Key Options

| Key | Default | Description |
|-----|---------|-------------|
| `notifications.database` | `false` | Persist notifications to the database via Laravel's `database` channel |
| `notifications.broadcast` | `false` | Broadcast system notifications (export/import done, etc.) in real time. Drives `Notification::shouldBroadcast()`; enable it (env `KINETIX_NOTIFICATIONS_BROADCAST`) when you wired up Echo/Reverb yourself instead of filling the `broadcasting.echo` block |
| `notifications.limit` | `15` | Max unread notifications loaded per page request |
| `notifications.poll` | `30000` | Database-mode fallback poll interval in **ms** (`0` disables). Without Echo, this is what makes new notifications appear — badge update **and** toast/sound for genuinely new unread items — without a page navigation. With Echo it's redundant but harmless (partial reloads only) |
| `notifications.teams` | `null` | Scope the bell per team: `true`/`false` wins, `null` inherits the global `kinetix.teams` switch (see [Team-scoped notifications](#team-scoped-notifications)) |
| `notifications.sound.enabled` | `true` | Play audio alert for incoming real-time notifications |
| `notifications.sound.path` | `/vendor/kinetix/notification.wav` | Path to the audio file |
| `broadcasting.echo` | `null` | Echo config for real-time WebSocket support |
| `route_prefix` | `_kinetix` | Prefix for Kinetix internal API routes |
| `middleware` | `['web', 'auth']` | Middleware applied to all internal routes |

---

## Backend Usage

### Fluent Builder

```php
use Happones\Kinetix\Notifications\Notification;

Notification::make()
    ->title('Saved successfully')
    ->description('Your changes have been saved.')
    ->success()
    ->duration(5000)
    ->send();
```

### Status Levels

| Method | Color |
|--------|-------|
| `->success()` | Green |
| `->warning()` | Amber |
| `->danger()` | Red |
| `->info()` | Blue (default) |

Each is a shortcut for `->status('success'|'warning'|'danger'|'info')`, so you can also set the level dynamically with `->status($level)`.

### Builder API

| Method | Description | Default |
|--------|-------------|---------|
| `->title(string)` | Notification heading | `''` |
| `->body(?string)` | Body text (`->description()` is an alias for this) | `null` |
| `->status(string)` | Status level — `info` · `success` · `warning` · `danger` (underlies the shortcut methods above) | `info` |
| `->duration(?int $ms)` | Auto-close delay in **milliseconds** | `6000` |
| `->seconds(int)` | Auto-close delay in **seconds** (shortcut for `->duration($seconds * 1000)`) | — |
| `->persistent()` | Never auto-close (shortcut for `->duration(null)`) | — |
| `->icon(?string)` | Icon name from `@lucide/vue` | `null` |
| `->iconColor(?string)` | Color of the notification icon | `null` |
| `->actions(array)` | Attach `Action` buttons/links (see [Actions](#actions)) | `[]` |
| `->team(int\|string\|null)` | Stamp the team the notification belongs to (see [Team-scoped notifications](#team-scoped-notifications)) | `null` |

```php
Notification::make()
    ->title('Backup complete')
    ->body('Last night\'s snapshot finished.')
    ->success()
    ->icon('database')
    ->iconColor('success')
    ->seconds(8)
    ->send();

// Keep an important alert on screen until dismissed:
Notification::make()->title('Action required')->danger()->persistent()->send();
```

### Persist to Database

By default, all notifications are session-based flash messages. To persist to the database, set `KINETIX_DATABASE_NOTIFICATIONS=true` in your `.env`, and either route using the fluent `to($user)` method or directly via `sendToDatabase($user)`:

```php
// Option A: Set recipient fluently (Recommended)
Notification::make()
    ->to($user)
    ->title('New task assigned')
    ->description('Ticket #4562 has been assigned to you.')
    ->info()
    ->send(); // Automatically routes to database if configured

// Option B: Pass recipient directly
Notification::make()
    ->title('New task assigned')
    ->description('Ticket #4562 has been assigned to you.')
    ->info()
    ->sendToDatabase($user);
```

### Real-Time Broadcasting

To broadcast in real-time via WebSockets (saves to DB **and** broadcasts):

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

This sends the notification to the `database` **and** `broadcast` channels simultaneously.

### Polling (database-mode fallback)

Without Echo, database notifications used to appear only on the next page
navigation. The bell now **polls** on an interval (a partial Inertia reload of
`kinetix_notifications` only) whenever database mode is on:

- Configure with `kinetix.notifications.poll` (**milliseconds**, default
  `30000`; `0` disables it). `usePoll` pauses in background tabs automatically.
- A genuinely **new unread** notification found by a poll triggers the toast +
  sound, exactly like a broadcast arrival. The initial page load stays silent —
  persisted rows are old news.
- If you run Echo/Reverb, polling is redundant; set
  `KINETIX_NOTIFICATIONS_POLL=0` or just leave it (the reloads are cheap and
  broadcast arrivals are deduplicated — nothing toasts twice).

### Team-scoped notifications

Route prefixing alone is **not** data isolation — team scoping filters the
actual list. It follows the suite's tri-state convention:
`kinetix.notifications.teams` set to `true`/`false` wins, `null` (default)
inherits the global `kinetix.teams` switch.

When on:

- **Stamping:** `Notification::team($key)` stamps a team PRIMARY key into the
  payload. Use `KinetixTeams::keyFor('notifications')` to resolve it — and
  capture it **at dispatch time** when sending from a queued job (the worker
  has no request). The built-in import/export jobs already do this
  automatically.
- **Filtering:** the bell lists only notifications stamped with the active team
  **plus unstamped (global) ones** — so `team(null)` deliberately means
  "visible in every team".
- **Broadcast:** the Echo channel stays per-user (`App.Models.User.{id}` —
  notifications are personal), so a notification for another team still
  arrives at the socket; the component suppresses its toast/sound when the
  stamp doesn't match the active team, and the server-filtered list ignores it.

```php
use Happones\Kinetix\Notifications\Notification;
use Happones\Kinetix\Support\KinetixTeams;

Notification::make()
    ->title('Invoice approved')
    ->team(KinetixTeams::keyFor('notifications'))
    ->success()
    ->sendToDatabase($user);
```

---

## Actions

Attach interactive buttons or links to notifications using `Action`:

```php
use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Notifications\Notification;

Notification::make()
    ->title('Backup complete')
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

### Action API

| Method | Description |
|--------|-------------|
| `::make(string $name)` | Create a new action |
| `->label(string $label)` | Button/link display text |
| `->icon(string $icon, string $position = 'before')` | Icon name from `@lucide/vue` |
| `->url(string $url, bool $openInNewTab = false)` | Navigate to URL on click |
| `->inertiaVisit(string $url, array $options = [])` | Use `router.visit()` for internal Inertia navigation |
| `->dispatch(string $event, array $data = [])` | Fires a `kinetix:{event}` browser `CustomEvent` |
| `->button()` | Render as a filled button (default) |
| `->link()` | Render as a text link |
| `->color(string $color)` | `primary`, `success`, `warning`, `danger`, `gray` |
| `->size(string $size)` | `xs`, `sm`, `md`, `lg` |
| `->close()` | Remove notification from list on click |
| `->markAsRead()` | Mark the parent notification as read on click |
| `->markAsUnread()` | Mark the parent notification as unread on click |

### Listening to Dispatched Events

When an action uses `->dispatch('my-event', ['id' => 42])`, listen in Vue:

```ts
window.addEventListener('kinetix:my-event', (e: Event) => {
    const detail = (e as CustomEvent).detail; // { id: 42 }
    // Handle event...
});
```

---

## Real-Time Broadcasting (Echo)

### 1. Configure Echo in `config/kinetix.php`

Uncomment the `broadcasting.echo` section and fill in your credentials:

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

The host Laravel project must have `@laravel/echo-vue` installed —
`kinetix:install` adds it automatically (the notifications component imports it
at build time). If you skipped the installer:

```bash
npm install @laravel/echo-vue
```

### 3. Configure Echo with `configureEcho`

Call `configureEcho` once in your app's entry point **before** any component mounts — typically in `resources/js/app.ts`:

```ts
import { configureEcho } from '@laravel/echo-vue';

// For Reverb (the defaults are filled in automatically based on VITE_ env vars)
configureEcho({
    broadcaster: 'reverb',
});

// The above is equivalent to:
configureEcho({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT,
    wssPort: import.meta.env.VITE_REVERB_PORT,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
```

> **No `window.Echo` needed.** `KinetixNotifications` uses `useEcho` from `@laravel/echo-vue` internally, which picks up the `configureEcho` instance automatically.

### 4. The Vue component auto-connects

`KinetixNotifications.vue` uses `useEchoNotification` internally to subscribe to the authenticated user's private Laravel notification channel. The channel format is:

```
private-{channelModel}.{userId}
```

By default `channelModel` is `App.Models.User`, which matches Laravel's standard User model namespace. **If your User model is in a different namespace or path, pass the `channelModel` prop:**

```vue
<!-- Default: App.Models.User.{id} -->
<KinetixNotifications />

<!-- Custom model namespace -->
<KinetixNotifications channel-model="App.Models.Admin" />
```

The component only connects when a user is authenticated (reads `page.props.auth.user.id` via `usePage`) and automatically disconnects on unmount.

### 5. Trigger real-time from PHP

```php
Notification::make()
    ->title('You have a new message')
    ->info()
    ->broadcast($user); // Saves to DB + broadcasts via Echo
```

---

## Multilingual Support (i18n)

Kinetix ships with translations for English, Spanish, French, and Portuguese.

### Publish & compile translations

```bash
php artisan vendor:publish --tag=kinetix-translations --force
php artisan vue-i18n:generate
```

Translations compile to `resources/js/vue-i18n-locales.ts` and are consumed via `t('kinetix.key')` in the Vue component.

---

## Frontend Integration

### Add to `AppSidebarHeader.vue`

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

        <!-- Kinetix notifications bell - always pushed to the far right -->
        <div class="ml-auto flex items-center gap-2">
            <KinetixNotifications />
        </div>
    </header>
</template>
```

### Mount `KinetixToaster` (themed toasts)

Toasts are rendered by `vue-sonner`, which needs a `<Toaster>` mounted once. **Use Kinetix's `<KinetixToaster />`** (not a raw `vue-sonner` `<Toaster>`): it styles toasts with shadcn semantic tokens so they read correctly in **dark mode** — a plain Toaster uses its own light-theme colors, so under `.dark` the text comes out dark-gray with no contrast.

> **Mount it exactly once, and remove any other `<Toaster>`.** vue-sonner uses a single global toast queue, so *every* mounted Toaster renders *every* toast. If your starter kit already mounts a raw `<Toaster>` (e.g. a shadcn `Sonner.vue`), that one will render the toast with light-theme colors — replace it with `<KinetixToaster />`, don't add alongside.

```vue
<!-- once, near the root of your layout -->
<script setup lang="ts">
import KinetixToaster from '@/components/kinetix/KinetixToaster.vue';
</script>

<template>
  <!-- ...app... -->
  <KinetixToaster position="top-right" :rich-colors="false" />
</template>
```

It forwards all `vue-sonner` Toaster props (`position`, `richColors`, `duration`, `expand`, …). The styling **redefines the CSS variables vue-sonner reads** (`--normal-bg`/`--normal-text`/`--normal-border` → `--popover`/`--popover-foreground`/`--border`) rather than overriding classes — class overrides lose the CSS-specificity battle when `vue-sonner/style.css` loads after Tailwind, which is the usual cause of a stubbornly white toast in dark mode. Since those tokens flip with `.dark`, the toast follows your theme automatically — no `theme` prop wiring needed.

### Server-flashed toasts (`kinetix_toast`)

`<KinetixToaster />` also watches the `kinetix_toast` flash prop, so ANY
controller redirect can fire a toast — no client wiring:

```php
// A plain string → success toast. Customize the message freely.
return redirect($url)->with('kinetix_toast', __('kinetix.record_created'));

// Or pick the variant explicitly:
return back()->with('kinetix_toast', ['type' => 'error', 'message' => __('app.sync_failed')]);
```

The Kinetix record endpoints (simple-resource modals, relation-manager modal
CRUD) and the `kinetix:make-resource` scaffolded controllers already flash it
on every create/update/delete/restore, using the generic
`kinetix.record_created` / `record_updated` / `record_deleted` /
`record_restored` / `record_force_deleted` messages — edit the scaffolded
`->with('kinetix_toast', …)` lines to customize per resource. The server
stamps a uuid per flash, so the same message twice in a row still fires.

---

## Artisan Commands

### Generate a custom notification class

```bash
php artisan kinetix:make-notification BackupSuccessNotification
```

### Send a test notification from CLI

```bash
php artisan kinetix:send-notification "Alert" "CPU usage at 90%" --status=warning --duration=5000
```

---

## Architecture

1. **Session flash (local)** — Default. `send()` flashes to session; Inertia's `kinetix_notifications` shared prop delivers them on next page load.
2. **Database persistence** — When `notifications.database = true`, notifications are written to the `notifications` table. The shared prop loads the latest unread records on every request.
3. **Real-time broadcast** — `broadcast($user)` writes to the database AND fires via Laravel Echo. The Vue component listens on the user's private channel and triggers instant toasts + sound without a page reload.
4. **Background fetch** — All mark-as-read / delete calls use `fetch()` with a CSRF header to avoid triggering Inertia's JSON modal response. A subsequent `router.reload({ only: ['kinetix_notifications'] })` keeps UI in sync.
