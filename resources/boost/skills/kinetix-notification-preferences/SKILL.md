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
  registered types and the channels each is delivered on).
- `KinetixNotificationPreferences::deliverOn('orders', ['mail', 'database'])`: a type
  sent on some channels only; its matrix row shows those switches alone.

## Gate a notification

```php
use Happones\Kinetix\NotificationPreferences\KinetixNotificationPreferences;

public function via(object $notifiable): array
{
    return KinetixNotificationPreferences::channelsFor($notifiable, 'orders', ['mail', 'database']);
}
```

Kinetix's own builder gates itself: `Notification::make()->type('orders')`
filters the database/broadcast channels against the recipient's matrix (no
`via()` needed). Only while the module is on AND the type is registered; no
type, or an unregistered one, delivers on every channel. Kinetix's notifications
carry `KinetixNotificationPreferences::EXPORTS` / `IMPORTS` / `REPORTS`
(`kinetix.exports`, …): register a key in `types` to let users turn it off.
They go out in-app and by broadcast only, so their rows have no mail switch.
A personal-data (GDPR) export is untyped and always delivered (`DATA_EXPORTS`
is deprecated). Rows are owned by key + model type (`notifiable_type`,
migrations 000039 + 000040), so credential-profile models never share choices.

## Frontend

```vue
<KinetixNotificationPreferences />
```

A type × channel checkbox matrix that persists each toggle.
`useKinetixNotificationPreferences()` → `{ matrix, loading, load, set }`. i18n
`notification_prefs_*` (en/es/fr/pt).

## UUID / ULID Host Models

This feature's migration builds `user_id` with
`Happones\Kinetix\Support\HostKeys`, which types each column after YOUR model
at migrate time (`HasUlids` -> ulid, `HasUuids` -> uuid, string `$keyType` ->
string, else bigint). Pin `kinetix.key_types.user|team` when detection cannot
see the setup; morph ids follow `kinetix.key_types.morph` (default bigint) —
set it when the referenced models use UUIDs/ULIDs. Apps migrated on an older
Kinetix have bigint columns on disk and need their own ALTER migration. Full
recipe: the `kinetix-boost` skill, section "UUID / ULID Host Models".
