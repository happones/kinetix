# Kinetix Notifications

Kinetix provides a beautiful, modern notification system designed specifically for **Vue 3**, **Inertia.js 3**, and **Shadcn**. Build and trigger backend notifications with a fluent PHP API, delivered instantly as toast popups and persisted in a bell-icon dropdown in the application header.

---

## Requirements

- **PHP**: 8.3+
- **Laravel**: 11, 12, or 13+
- **Inertia.js**: v3
- **Vue**: v3
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
        'database' => env('KINETIX_DATABASE_NOTIFICATIONS', false),
        'limit'    => env('KINETIX_NOTIFICATIONS_LIMIT', 15),
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
| `notifications.limit` | `15` | Max unread notifications loaded per page request |
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

### Persist to Database

By default, all notifications are session-based flash messages. To persist to the database, set `KINETIX_DATABASE_NOTIFICATIONS=true` in your `.env`, then call `->sendToDatabase($user)`:

```php
Notification::make()
    ->title('New task assigned')
    ->description('Ticket #4562 has been assigned to you.')
    ->info()
    ->sendToDatabase($user);
```

Or use `->send()` when `database` is `true` in config — it automatically routes to the database.

### Real-Time Broadcasting

To broadcast in real-time via WebSockets (requires Laravel Echo + Reverb/Pusher):

```php
Notification::make()
    ->title('Server alert')
    ->description('CPU usage exceeded 85%.')
    ->danger()
    ->broadcast($user);
```

This sends the notification to the `database` **and** `broadcast` channels simultaneously.

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

The host Laravel project must have `@laravel/echo-vue` installed (comes pre-installed in the Laravel starter kits):

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
