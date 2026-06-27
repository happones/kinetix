---
name: kinetix-notification-preferences
description: "Per-user notification opt-in matrix (types × channels: email/in-app/push). Gate a Notification's via() against user choices. Activates when adding notification preferences or per-channel opt-outs."
license: MIT
metadata:
  author: happones
---

# Kinetix Notification Preferences Development

## When to Apply

Activate this skill when:
- Adding a notification-preferences screen (type × channel matrix).
- Letting users opt out of certain notifications per channel.
- Respecting those choices when sending Laravel notifications.

## Documentation

For full details, reference `docs/notification-preferences.md` (published at https://happones.github.io/kinetix/notification-preferences).

## Installation & Configuration

```bash
php artisan vendor:publish --tag=kinetix-notification-preferences-migrations
php artisan migrate
```

```php
'notification_preferences' => [
    'enabled'  => env('KINETIX_NOTIFICATION_PREFERENCES_ENABLED', false),
    'channels' => ['mail' => 'Email', 'database' => 'In-app', 'broadcast' => 'Push'],
    'types'    => ['orders' => 'Order updates', 'marketing' => 'Marketing & tips'],
],
```

Types can also be registered with `KinetixNotificationPreferences::types([...])`.
Defaults to enabled — only opt-outs are stored.

---

## Backend

- `NotificationPreferenceManager`: `for($user)` (matrix), `update($user, $type,
  $channel, $enabled)`, `allows($user, $type, $channel)`, `channelsFor($user,
  $type, $channels)`.
- `NotificationPreferenceController` (self-service, team-aware
  `{prefix}/notification-preferences`): `index`, `update` (validated against the
  registered types + channels).

## Gate a notification

```php
use Happones\Kinetix\NotificationPreferences\KinetixNotificationPreferences;

public function via(object $notifiable): array
{
    return KinetixNotificationPreferences::channelsFor($notifiable, 'orders', ['mail', 'database']);
}
```

## Frontend

```vue
<KinetixNotificationPreferences />
```

A type × channel checkbox matrix that persists each toggle.
`useKinetixNotificationPreferences()` → `{ matrix, loading, load, set }`. i18n
`notification_prefs_*` (en/es/fr/pt).
