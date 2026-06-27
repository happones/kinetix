# Notification Preferences

Kinetix Notification Preferences gives users a **type × channel opt-in matrix**:
each notification *type* your app defines (order updates, mentions, marketing…)
can be toggled per delivery *channel* (email, in-app, push). It pairs with the
[Notifications](/notifications) module — gate a Laravel notification's channels
against the user's preferences before sending.

Defaults to **enabled**: only opt-outs are stored, so newly added types/channels
are on until the user turns them off.

<Screenshot name="notification-preferences" alt="Notification preferences matrix" />

---

## Installation

```bash
php artisan vendor:publish --tag=kinetix-notification-preferences-migrations
php artisan migrate
```

Enable the feature, declare the channels and notification types:

```php
'notification_preferences' => [
    'enabled'  => env('KINETIX_NOTIFICATION_PREFERENCES_ENABLED', true),
    'channels' => [
        'mail'      => 'Email',
        'database'  => 'In-app',
        'broadcast' => 'Push',
    ],
    'types' => [
        'orders'    => 'Order updates',
        'marketing' => 'Marketing & tips',
    ],
],
```

Types can also be registered at runtime:

```php
use Happones\Kinetix\NotificationPreferences\KinetixNotificationPreferences;

KinetixNotificationPreferences::types([
    'orders'   => 'Order updates',
    'mentions' => 'Mentions & replies',
]);
```

---

## The component

```vue
<script setup lang="ts">
import KinetixNotificationPreferences from '@/components/KinetixNotificationPreferences.vue';
</script>

<template>
    <KinetixNotificationPreferences />
</template>
```

A row per type, a column per channel, each cell a checkbox that persists
immediately. `useKinetixNotificationPreferences()` exposes
`{ matrix, loading, load, set }` for a custom UI. Strings are localized
(`notification_prefs_*`, en/es/fr/pt).

---

## Gating a notification

Respect the user's choices inside a notification's `via()` — return only the
channels they accept for that type:

```php
use Happones\Kinetix\NotificationPreferences\KinetixNotificationPreferences;

class OrderShipped extends Notification
{
    public function via(object $notifiable): array
    {
        return KinetixNotificationPreferences::channelsFor(
            $notifiable,
            'orders',                 // the notification type key
            ['mail', 'database'],     // the channels this notification can use
        );
    }
}
```

Or check a single channel with
`KinetixNotificationPreferences::allows($user, $type, $channel)`.

---

## Endpoints

Registered under your Kinetix prefix (team-aware when `kinetix.teams` is on):

| Method | Route                              | Name                                    |
| ------ | ---------------------------------- | --------------------------------------- |
| `GET`  | `{prefix}/notification-preferences`| `kinetix.notification-preferences.index` |
| `POST` | `{prefix}/notification-preferences`| `kinetix.notification-preferences.update` |

`index` returns the full matrix; `update` sets one `{type, channel, enabled}`
(validated against the registered types + channels).
