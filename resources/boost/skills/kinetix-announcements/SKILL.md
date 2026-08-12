---
name: kinetix-announcements
description: "A 'what's new' announcements feed with a per-user unread badge, plus an in-page rotating banner. Publish product updates with KinetixAnnouncements::publish(); mount the header trigger or the banner. Activates when adding a changelog/announcements/what's-new feed or an in-app announcement banner."
license: MIT
metadata:
  author: happones
---

# Kinetix Announcements Development

## When to Apply

Activate this skill when:
- Adding a "what's new" / product-announcements / changelog feed.
- Mounting the `<KinetixAnnouncements>` header trigger or showing an unread badge.
- Showing announcements inside a page as a banner/carousel
  (`<KinetixAnnouncementBanner>`) instead of a header popover.

## Documentation

For full details, reference `docs/announcements.md` (published at https://happones.github.io/kinetix/announcements).

## Installation & Configuration

```bash
php artisan vendor:publish --tag=kinetix-announcements-migrations
php artisan migrate
```

```php
'announcements' => [
    'enabled'      => env('KINETIX_ANNOUNCEMENTS_ENABLED', false),
    'banner_limit' => env('KINETIX_ANNOUNCEMENTS_BANNER_LIMIT', 3),
],
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

- `AnnouncementManager`: `feed($user)` (published, `isNew` flag), `banner($user,
  $limit, $levels)` (undismissed only), `unreadCount`, `markSeen`, `dismiss`,
  `create`. "Unread" = entries published after the user's last-seen timestamp —
  one per (user, team), plus one for the platform-wide pool, so reading team A's
  feed never clears team B's badge and a global entry is read once, everywhere.
- `AnnouncementController` (self-service, team-aware `{prefix}/announcements`):
  `GET /` (feed + unread), `GET banner`, `POST seen`, `POST {id}/dismiss`.
  Dismiss resolves through the team-scoped query — another tenant's id is a 404.

## Frontend

```vue
<KinetixAnnouncements />                            <!-- header trigger -->
<KinetixAnnouncementBanner                          <!-- in-page banner -->
    :limit="3"
    :levels="['feature', 'fix']"
    :autoplay="8000"
    dismissible
/>
<KinetixAnnouncementBanner position="fixed-top" /> <!-- pinned bar -->
```

Megaphone trigger + unread badge; opening the popover lists the feed and marks it
seen (clearing the badge). `useKinetixAnnouncements()` →
`{ announcements, unread, loading, load, markSeen }`.

The banner rotates one entry at a time (arrows, dots, pause button, left/right
keys), pauses on hover/focus, and drops auto-rotation under
`prefers-reduced-motion`. Dismissing is **per announcement** and server-side —
it does not mark the feed read.

`position="fixed-top"` pins it to the viewport (below Kinetix's overlays) and
publishes its height as `--kinetix-announcement-banner-height` on `<html>`, so
the layout can reserve the space with
`padding-top: var(--kinetix-announcement-banner-height, 0px)`.
`fixedWidthClass` (default `max-w-3xl`) sizes the pinned bar. `useKinetixAnnouncementBanner({ limit, levels })`
→ `{ announcements, loading, load, dismiss }`;
`useKinetixAnnouncementFormat()` → `{ levelClass, levelLabel, formatDate }`
(shared level colours/labels + dates in the app's locale).

i18n `announcements_*` (7 locales).

## UUID / ULID Host Models

This feature's migration builds `user_id` and `team_id` with
`Happones\Kinetix\Support\HostKeys`, which types each column after YOUR model
at migrate time (`HasUlids` -> ulid, `HasUuids` -> uuid, string `$keyType` ->
string, else bigint). Pin `kinetix.key_types.user|team` when detection cannot
see the setup; morph ids follow `kinetix.key_types.morph` (default bigint) —
set it when the referenced models use UUIDs/ULIDs. Apps migrated on an older
Kinetix have bigint columns on disk and need their own ALTER migration. Full
recipe: the `kinetix-boost` skill, section "UUID / ULID Host Models".
