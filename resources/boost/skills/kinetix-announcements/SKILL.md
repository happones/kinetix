---
name: kinetix-announcements
description: "A 'what's new' announcements feed with a per-user unread badge. Publish product updates with KinetixAnnouncements::publish(); mount the header trigger. Activates when adding a changelog/announcements/what's-new feed."
license: MIT
metadata:
  author: happones
---

# Kinetix Announcements Development

## When to Apply

Activate this skill when:
- Adding a "what's new" / product-announcements / changelog feed.
- Mounting the `<KinetixAnnouncements>` header trigger or showing an unread badge.

## Documentation

For full details, reference `docs/announcements.md` (published at https://happones.github.io/kinetix/announcements).

## Installation & Configuration

```bash
php artisan vendor:publish --tag=kinetix-announcements-migrations
php artisan migrate
```

```php
'announcements' => ['enabled' => env('KINETIX_ANNOUNCEMENTS_ENABLED', false)],
```

## Publishing

```php
use Happones\Kinetix\Announcements\KinetixAnnouncements;

KinetixAnnouncements::publish('v2.0 is here', 'Dark mode, faster search…', 'feature');
KinetixAnnouncements::publish('Heads up', '…', 'info', now()->addDay()); // scheduled
```

Levels: `info` (default) | `feature` | `fix`. Only entries with a past
`published_at` show; `null` = draft.

## Backend

- `AnnouncementManager`: `feed($user)` (published, `isNew` flag), `unreadCount`,
  `markSeen`, `create`. Per-user "unread" = entries published after the user's
  single last-seen timestamp.
- `AnnouncementController` (self-service, team-aware `{prefix}/announcements`):
  `GET /` (feed + unread), `POST seen`.

## Frontend

```vue
<KinetixAnnouncements />
```

Megaphone trigger + unread badge; opening the popover lists the feed and marks it
seen (clearing the badge). `useKinetixAnnouncements()` →
`{ announcements, unread, loading, load, markSeen }`. i18n `announcements_*`.

## UUID / ULID Host Models

The published migration types `user_id` and `team_id` as `unsignedBigInteger`. If the
referenced model uses UUIDs or ULIDs, publish
`--tag=kinetix-announcements-migrations` and retype those columns
(`$table->uuid(…)` / `$table->ulid(…)`) BEFORE `php artisan migrate` —
type each column after the model it points to. Full recipe: the
`kinetix-boost` skill, section "UUID / ULID Host Models".
