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
    'feed_limit'   => env('KINETIX_ANNOUNCEMENTS_FEED_LIMIT', 20),
    'banner_limit' => env('KINETIX_ANNOUNCEMENTS_BANNER_LIMIT', 3),
    'share'        => env('KINETIX_ANNOUNCEMENTS_SHARE', true),
],
```

## Publishing

From the app (`<KinetixAnnouncementManager>`, gated by the
`manageKinetixAnnouncements` ability — defaults to `local` only):

```php
Gate::define('manageKinetixAnnouncements', fn ($user) => $user->isAdmin());
```

No publish date = draft; a future date = scheduled. Both stay out of every
reader feed until their moment. With teams on, a platform-wide entry is
read-only inside a team (403) — it belongs to every tenant.

From code:

```php
use Happones\Kinetix\Announcements\KinetixAnnouncements;

KinetixAnnouncements::publish('v2.0 is here', 'Dark mode, faster search…', 'feature');
KinetixAnnouncements::publish('Heads up', '…', 'info', now()->addDay()); // scheduled
KinetixAnnouncements::publish('Maintenance', '…', 'info', now(), now()->addWeek()); // expires
```

Levels: `info` (default) | `feature` | `fix`. Only entries with a past
`published_at` show; `null` = draft. `expires_at` ends it: once it passes the
entry leaves every feed, banner and unread count (`null` = never expires). The
feed returns 20 (`announcements.feed_limit`, `?limit=` up to 50) — no cursor,
by design.

## Backend

- `AnnouncementManager`: `feed($user)` (published, `isNew` flag), `banner($user,
  $limit, $levels)` (undismissed only), `unreadCount`, `markSeen`, `dismiss`,
  `create`. "Unread" = entries published after the user's last-seen timestamp —
  one per (user, team), plus one for the platform-wide pool, so reading team A's
  feed never clears team B's badge and a global entry is read once, everywhere.
- `AnnouncementController` (self-service, team-aware `{prefix}/announcements`):
  `GET /` (feed + unread), `GET banner`, `POST seen`, `POST {id}/dismiss`, plus
  the authoring half — `GET manage`, `POST /`, `PUT|DELETE {id}` — behind
  `manageKinetixAnnouncements`. Every lookup goes through the team-scoped
  query, so another tenant's id is a 404.

## Frontend

```vue
<KinetixAnnouncementManager />                      <!-- authoring page -->
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

## Cost per mount

`kinetix_announcements` (shared on every Inertia response:
`{ unread, bannerLimit, banner }`, `null` for guests or when
`announcements.share` is false) feeds both components, so neither fetches on
mount. The popover loads its list once, on open; the banner fetches only when
narrowed past the shared shape (`levels`, or a `limit` other than
`banner_limit`).

## UUID / ULID Host Models

This feature's migration builds `user_id` and `team_id` with
`Happones\Kinetix\Support\HostKeys`, which types each column after YOUR model
at migrate time (`HasUlids` -> ulid, `HasUuids` -> uuid, string `$keyType` ->
string, else bigint). Pin `kinetix.key_types.user|team` when detection cannot
see the setup; morph ids follow `kinetix.key_types.morph` (default bigint) —
set it when the referenced models use UUIDs/ULIDs. Apps migrated on an older
Kinetix have bigint columns on disk and need their own ALTER migration. Full
recipe: the `kinetix-boost` skill, section "UUID / ULID Host Models".
