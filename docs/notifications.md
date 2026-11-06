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
| `notifications.poll` | `30000` | Database-mode fallback poll interval in **ms** (`0` disables). Without Echo, this is what makes new notifications appear — badge update **and** toast/sound for genuinely new unread items — without a page navigation. Paused while the tab is hidden. With Echo it's redundant but harmless (each tick is one small JSON request) |
| `notifications.teams` | `null` | Scope the bell per team: `true`/`false` wins, `null` inherits the global `kinetix.teams` switch (see [Team-scoped notifications](#team-scoped-notifications)) |
| `notifications.sound.enabled` | `true` | Play audio alert for incoming real-time notifications |
| `notifications.sound.path` | `/vendor/kinetix/notification.wav` | Path to the audio file |
| `broadcasting.echo` | `null` | Echo config for real-time WebSocket support |
| *(shared)* `kinetix_config.realtime` | derived | Whether the bell loads Echo at all — see [When the bell listens](#when-the-bell-listens) |
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
| `->type(?string)` | The [notification preferences](/notification-preferences#kinetix-notifications-follow-the-matrix) type: database/broadcast deliveries skip the channels the recipient turned off for it | `null` |

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
navigation. The bell now **polls** the [notification feed](#the-notification-feed)
on an interval whenever database mode is on:

- Configure with `kinetix.notifications.poll` (**milliseconds**, default
  `30000`; `0` disables it). The interval counts from the end of one request to
  the next, so a slow response is never overlapped. Polling pauses while the
  tab is hidden and refreshes once as soon as it's visible again. There's no
  request at mount: the page already carries the current list. A failed
  request (offline, an expired session) only logs a console warning; the next
  tick tries again.
- A genuinely **new unread** notification found by a poll triggers the toast +
  sound, exactly like a broadcast arrival. The initial page load stays silent —
  persisted rows are old news.
- If you run Echo/Reverb, polling is redundant; set
  `KINETIX_NOTIFICATIONS_POLL=0` or just leave it (each tick is one small JSON
  request, and broadcast arrivals are deduplicated — nothing toasts twice).

### The notification feed

`GET {prefix}/notifications` (route name `kinetix.notifications.index`, under
the team prefix when teams are on) returns the bell's list as JSON:

```json
{ "notifications": [ { "id": "…", "title": "Export ready", "status": "success", "read": false, "created_at": "…" } ] }
```

- It holds exactly what the `kinetix_notifications` page prop holds: the same
  limit, the same [team filter](#team-scoped-notifications), newest first. Its
  TypeScript type is `KinetixNotificationsFeed`.
- The bell reads it on every poll tick, when a database-mode broadcast
  arrives, after mark-as-read / delete / clear, and to roll back an optimistic
  change that failed.
- It runs **only** Kinetix's feed query. The bell used to refresh through a
  partial Inertia reload, which re-ran the controller of whatever page was
  open (a scaffolded index: count, page, eager loads) every 30 seconds per
  open tab.
- It carries the module's middleware like its sibling routes: the
  `kinetix.middleware` stack and `kinetix.module_middleware.notifications`.
  Guests get a `401`. Responses are `Cache-Control: no-store, private`.
- To refresh the bell from your own code (after an action of yours created a
  notification), call the store's `refresh()`:

```ts
import { useNotificationsStore } from '@/stores/kinetixNotifications';

await useNotificationsStore().refresh();
```

### Index for the feed query

The bell reads `where notifiable_type = ? and notifiable_id = ? order by
created_at desc limit ?` on every page and every poll. Laravel's own
`notifications` table only indexes the two notifiable columns, so the database
sorts every notification the user ever received to return the newest few. The
optional `kinetix-notifications-migrations` tag adds a
`(notifiable_type, notifiable_id, created_at)` index:

```bash
php artisan vendor:publish --tag=kinetix-notifications-migrations
php artisan migrate
```

The migration skips a missing table and any index that already starts with
those columns (whatever its name), and `down()` drops only its own index.
`php artisan kinetix:doctor` warns when database notifications are on and the
index is missing.

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
- **Mark all as read / clear all** act on exactly what the bell shows: the
  active team's notifications plus the global ones. Another team's stay
  unread and are kept. "Mark all as read" is a single `UPDATE`, however many
  notifications are unread.
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
`kinetix:install` adds it automatically. It's a required peer: the bell imports
it on demand, so the build must be able to resolve it. If you skipped the
installer:

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

> **No `window.Echo` needed.** `KinetixNotifications` uses the `configureEcho` instance from `@laravel/echo-vue` automatically.

### 4. The Vue component auto-connects

`KinetixNotifications.vue` subscribes to the authenticated user's private Laravel notification channel. The channel format is:

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

The component only connects when a user is authenticated (reads `page.props.auth.user.id` via `usePage`) and automatically disconnects on unmount. On unmount it stops listening for notifications but doesn't leave the private channel, because your own code may share it.

#### When the bell listens

`@laravel/echo-vue` is a required peer, but the bell imports it only when it
listens in real time, after it has mounted. Whether it does comes from, in
order:

1. the `realtime` prop: `<KinetixNotifications :realtime="false" />` never
   loads Echo, `:realtime="true"` always tries;
2. the shared `kinetix_config.realtime`, which the server turns on when
   `kinetix.notifications.broadcast` is on, the `broadcasting.echo` block is
   filled, or `broadcasting.default` is a real driver (not `null` or `log`);
3. on an older server without that flag, whether a `kinetix_config.broadcasting`
   block is shared.

Echo is loaded only when the bell listens **and** Echo is configured.

When Echo isn't configured (`configureEcho()` never called), the bell skips the
subscription and keeps polling. It never errors. Hosts without broadcasting
don't download Echo or `pusher-js` at all. See
[Performance](/performance#notifications-and-presence).

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

> **The vue-sonner stylesheet is required.** Without
> `@import 'vue-sonner/style.css';` in `resources/css/app.css` (after the
> Tailwind import), toasts render as **unstyled plain text** — no container,
> no animation, no colors. `kinetix:install` adds the import automatically;
> if you installed before v0.152, add it manually.

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

### Flash toasts (`KinetixFlash`)

`<KinetixToaster />` shows the toasts any controller flashes for the next page,
with no client wiring:

```php
use Happones\Kinetix\Flash\KinetixFlash;

KinetixFlash::success(__('invoices.sent'));
KinetixFlash::error(__('app.sync_failed'), __('app.sync_failed_hint')); // + description
KinetixFlash::toast(__('imports.queued'), 'info', duration: 8000);       // any type, ms

return back();
```

They travel on Inertia's own flash channel, which the browser history never
stores, so pressing Back can't replay a toast. Several toasts in one request
all show. From the client, `useKinetixFlash()` flashes the same way without a
request: `useKinetixFlash().success(t('app.copied'))`.

For a message that should stay on the page rather than pop up and fade, flash
an alert instead — see [Alerts → Flashed from the server](/alerts#flashed-from-the-server).

### Server-flashed toasts (`kinetix_toast`)

`<KinetixToaster />` also watches the `kinetix_toast` flash prop, so ANY
controller redirect can fire a toast — no client wiring:

```php
// A plain string → success toast. Customize the message freely.
return redirect($url)->with('kinetix_toast', __('kinetix.record_created'));

// Or pick the variant explicitly:
return back()->with('kinetix_toast', ['type' => 'error', 'message' => __('app.sync_failed')]);
```

It keeps working for your own code. The server stamps a uuid per flash, so
the same message twice in a row still fires. This prop is a page prop, so the
history keeps it; the toaster remembers every id it has shown, so a page
restored by Back stays silent.

Kinetix's own endpoints flash through `KinetixFlash` instead: the record
endpoints (simple-resource modals, relation-manager modal CRUD), the password
change, the forced-password-change redirect (a **warning**) and the plan
upsell redirect (an **info**). So do the `kinetix:make-resource` scaffolded
controllers, with the generic `kinetix.record_created` / `record_updated` /
`record_deleted` / `record_restored` / `record_force_deleted` messages — edit
the scaffolded `KinetixFlash::success(…)` lines to customize per resource. A
custom toaster of your own that reads `kinetix_toast` should read
`flash.kinetix.toasts` too (`onKinetixFlash()` hands both the current page's
and every later flash).

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
2. **Database persistence** — When `notifications.database = true`, notifications are written to the `notifications` table. The shared prop loads the latest records on every request; the bell refreshes them through the [JSON feed](#the-notification-feed).
3. **Real-time broadcast** — `broadcast($user)` writes to the database AND fires via Laravel Echo. The Vue component listens on the user's private channel and triggers instant toasts + sound without a page reload. Echo is loaded on demand, only when [the bell listens](#when-the-bell-listens).
4. **Background fetch** — All mark-as-read / delete calls use `fetch()` with a CSRF header to avoid triggering Inertia's JSON modal response. A request to the [feed](#the-notification-feed) then keeps the UI in sync, without re-running the page's controller.
