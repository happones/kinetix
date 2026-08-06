---
name: kinetix-notifications
description: "Handles integration, generation, translations, and dispatch of Kinetix Notifications. Activates when sending notifications, configuring drivers, running translations compilation, or using the KinetixNotifications component."
license: MIT
metadata:
  author: happones
---

# Kinetix Notifications Development

## When to Apply

Activate this skill when:

- Customizing or creating notifications using `Happones\Kinetix\Notifications\Notification`.
- Routing notifications to specific users using `to($user)`.
- Configuring or publishing Kinetix configuration (`config/kinetix.php`).
- Synchronizing and compiling multilingual translations using the `php artisan vue-i18n:generate` command.
- Troubleshooting state management (e.g. database read/delete actions) or WebSockets real-time broadcasts (Echo).
- Placing and aligning the `<KinetixNotifications />` Vue component.

## Documentation

For full details, reference the [Kinetix Notifications Documentation](https://happones.github.io/kinetix/notifications).

## Basic Usage

### Dispatching Notifications

Configure the active driver inside `config/kinetix.php`.

#### 1. Local Driver (Session-based)
Ideal for standard redirects and instant single-session flash alerts:

```php
use Happones\Kinetix\Notifications\Notification;

Notification::make()
    ->title('Welcome Back!')
    ->description('You have successfully logged in.')
    ->success()
    ->send();
```

#### 2. Database and Broadcast Drivers
Persistent or real-time alerts. Requires a recipient user (defaults to `auth()->user()` if omitted):

```php
use Happones\Kinetix\Notifications\Notification;

$user = auth()->user();

Notification::make()
    ->to($user)
    ->title('New Assigned Ticket')
    ->description('Ticket #4928 was assigned to you.')
    ->info()
    ->send();
```

---

## Multilingual (i18n) Usage

Kinetix is fully internationalized. Never hardcode text inside components.

1.  Manage translation files in `lang/{locale}/kinetix.php`.
2.  Publish translations:
    ```bash
    php artisan vendor:publish --tag=kinetix-translations --force
    ```
3.  Compile to TypeScript:
    ```bash
    php artisan vue-i18n:generate
    ```
4.  Reference the keys in Vue components using `$t()` or `t()`:
    *   `t('kinetix.notifications')`
    *   `t('kinetix.new_notifications')`
    *   `t('kinetix.mark_all_as_read')`
    *   `t('kinetix.clear_all')`
    *   `t('kinetix.no_notifications')`
    *   `t('kinetix.minutes_ago', { minutes })`
    *   `t('kinetix.hours_ago', { hours })`

---

## Artisan Commands

### Custom Class Generation
```bash
php artisan kinetix:make-notification BackupSuccessNotification
```

### Testing UI Delivery
```bash
php artisan kinetix:send-notification "Alert Title" "Alert description message" --status=success --duration=4000
```

---

## Server-flashed toasts (`kinetix_toast` protocol)

Any controller redirect can fire a themed toast with zero client wiring —
`<KinetixToaster />` watches the `kinetix_toast` shared prop. The watcher
accepts exactly two shapes:

```php
// 1. Short string → success toast (the common case):
return back()->with('kinetix_toast', __('kinetix.record_created'));

// 2. Structured array → explicit variant ('success'|'error'|'info'|'warning'):
return back()->with('kinetix_toast', ['type' => 'error', 'message' => __('app.sync_failed')]);
```

Rules that matter:

- **The server stamps a uuid per flash**, so the same message twice in a row
  still fires twice — never add your own dedupe.
- An unknown `type` falls back to `success`; a payload without a string
  `message` is dropped silently.
- The Kinetix record endpoints (simple-resource modals, relation-manager
  modal CRUD) and `kinetix:make-resource` controllers already flash it with
  the generic `kinetix.record_created` / `record_updated` / `record_deleted`
  / `record_restored` / `record_force_deleted` keys — customize by editing
  the `->with('kinetix_toast', …)` line, never by adding a second toast
  mechanism.
- **Two hard prerequisites** (each fails as "toasts don't show" / "toasts
  render as unstyled plain text"): `<KinetixToaster />` mounted exactly once
  in the layout, and `@import 'vue-sonner/style.css';` in
  `resources/css/app.css` **after** the Tailwind import (`kinetix:install`
  adds it; verify in older installs — without the stylesheet the toast is
  plain text with no container, animation, or colors).

## Layout Integration

Publish components:
```bash
php artisan vendor:publish --tag=kinetix-components --force
```

#### Option A: App Header Layout (`resources/js/components/AppHeader.vue`)
```vue
<template>
    <div class="ml-auto flex items-center space-x-2">
        <KinetixNotifications />
    </div>
</template>
```

#### Option B: App Sidebar Header Layout (`resources/js/components/AppSidebarHeader.vue`)
```vue
<template>
    <header class="flex h-16 shrink-0 items-center border-b px-6">
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <Breadcrumbs :breadcrumbs="breadcrumbs" />
        </div>
        
        <div class="ml-auto flex items-center gap-2">
            <KinetixNotifications />
        </div>
    </header>
</template>

---

## Best Practices

- **Translations & Documentation**: Do not hardcode strings; always define them in translations and keep documentation updated for any new components or options.
- **Polling fallback (database mode)**: without Echo the bell polls `kinetix_notifications` on `kinetix.notifications.poll` ms (default `30000`, `0` = off); a genuinely new unread item found by a poll toasts + plays the sound, the initial page load stays silent. Do not add ad-hoc `setInterval` reloads.
- **Team scoping**: `kinetix.notifications.teams` is tri-state (`null` inherits the global `kinetix.teams`). Stamp with `->team(KinetixTeams::keyFor('notifications'))` — captured at DISPATCH time for queued jobs (the worker has no request; import/export jobs do this automatically). Unstamped notifications are global and show in every team. The Echo channel stays per-user; other-team toasts are suppressed client-side via `kinetix_config.team_id`.
```
