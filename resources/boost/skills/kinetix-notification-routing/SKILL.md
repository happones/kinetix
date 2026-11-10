---
name: kinetix-notification-routing
description: "Per-team routing of notification types to members, roles, groups or everyone eligible, on chosen channels, with code-only mandatory channels and a recipients preview. Activates when sending a platform alert to whoever a team picked, or touching KinetixNotificationRouting, routes, groups or mandatory channels."
license: MIT
metadata:
  author: happones
---

# Kinetix Notification Routing Development

## When to Apply

Activate this skill when:

- Sending a notification to an audience each team configures (not one named user).
- Declaring `requires()` / `mandatory()` for a notification type.
- Touching the routing endpoints, groups, `AudienceResolver` or its jobs.

## Documentation

For full details, reference `docs/notification-routing.md` (published at https://happones.github.io/kinetix/notification-routing).

## Configuration

```bash
php artisan vendor:publish --tag=kinetix-notification-routing-migrations
php artisan migrate
```

```php
'notification_routing' => [
    'enabled' => env('KINETIX_NOTIFICATION_ROUTING_ENABLED', false),
    'teams'   => env('KINETIX_NOTIFICATION_ROUTING_TEAMS'), // null = inherit kinetix.teams
    'queue'   => null, 'chunk' => 100, 'max_recipients' => 500,
    'members' => null, // [Class::class, 'method'](teamKey) | null -> team model's members_relation
    'members_relation' => 'members', 'name_attribute' => 'name',
    'preview_throttle' => '30,1',
],
```

## Usage

```php
KinetixNotificationPreferences::types(['inventory.low-stock' => 'Low stock']); // the routable list
KinetixNotificationRouting::type('inventory.low-stock')->requires('products.viewAny')->mandatory(['mail']);

KinetixNotificationRouting::for('inventory.low-stock', $team->getKey())
    ->fallback($team->owners)->notify(new LowStockAlert($product)); // notifyNow() = in-process
KinetixNotificationRouting::for(...)->send(Notification::make()->title('...'));  // Kinetix in-app
KinetixNotificationRouting::for(...)->recipients();                              // dry run
```

## Rules

- **Types are the preferences registry's.** No second registry; `requires` and
  `mandatory` live in `NotificationTypeRegistry`. Kinetix's own `kinetix.*` types
  are not routable.
- **`mandatory()` is code-only.** No endpoint or screen sets it; admins route,
  never override an opt-out. Never for marketing types. The preferences matrix
  shows those channels locked (`types[].mandatory`); turning one off is a 422.
- **Team is the caller's.** `for($type, $teamPrimaryKey)` captures it at dispatch;
  the worker never infers it. Rules/groups use `ScopedToTeam` (module
  `notification_routing`); every endpoint id is re-scoped, another team's id is a 404.
- **Recipients are current team members only.** Roles and groups are
  intersected with `KinetixTeams::members()`; stale rows reach nobody.
  `eligible` = members passing `requires`, judged at send time; never skipped.
- **One notification per person**, on the union of their rules' channels ∩ the
  type's `deliverOn` ∩ what the notification can render; opt-outs removed in ONE
  batched query (`storedMany`), except mandatory. An empty channel set is skipped,
  never passed to `sendNow` (an empty list means "use `via()`").
- **Fixed query count** whatever the audience; only `requires` is per person.
- **Database channel** is `TeamStampedDatabaseChannel`: stamps `data.team` so a
  host notification stays in its team's bell.
- **Callbacks are callable arrays**, never closures (`config:cache`).
- Endpoints (`kinetix.notification-routing.*`) need `notification_routing.manage`.
- Events: `AudienceResolved` (counts only), `RoutedNotificationSent`,
  `NotificationRouteChanged`; `GdprPurged` removes a purged account from groups
  and member rules.

## Frontend

Components: `KinetixNotificationRouting`, `KinetixNotificationGroups` (both take `hide-title`), composable `useKinetixNotificationRouting()`; `KinetixNotificationPreferences` locks mandatory channels. Member ids are opaque strings (never parse them). Strings: `kinetix.notification_routing_*`.

Payload types in `resources/js/types/kinetix.ts`
(`KinetixNotificationRoutingType`, `KinetixNotificationRoute`,
`KinetixNotificationGroup`, `KinetixNotificationAudience`,
`KinetixNotificationRecipients`).

## UUID / ULID Host Models

The migrations build `team_id` and `user_id` with `Happones\Kinetix\Support\HostKeys`
(typed after your models at migrate time). See the `kinetix-boost` skill,
section "UUID / ULID Host Models".
