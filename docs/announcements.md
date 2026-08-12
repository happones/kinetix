# Announcements

Kinetix Announcements is a **"What's new" feed** with a per-user unread badge.
Publish product updates (features, fixes, news); each user sees a count of
entries published since they last opened the feed, which clears when they read it.

<Screenshot name="announcements" alt="What's new announcements feed" />

---

## Installation

```bash
php artisan vendor:publish --tag=kinetix-announcements-migrations
php artisan migrate
```

```php
'announcements' => [
    'enabled' => env('KINETIX_ANNOUNCEMENTS_ENABLED', true),
],
```

---

## Publishing from the app

Announcements used to be publish-from-code only, which made every "heads up,
maintenance on Sunday" a deploy. `<KinetixAnnouncementManager>` is the authoring
side: write, schedule, edit and delete, from a page in your app.

<Screenshot name="announcement-manager" alt="Announcement manager listing a draft, a scheduled entry and a published one" />

```vue
<script setup lang="ts">
import KinetixAnnouncementManager from '@/components/kinetix/KinetixAnnouncementManager.vue';
</script>

<template>
    <KinetixAnnouncementManager />
</template>
```

Authoring is gated by the **`manageKinetixAnnouncements`** ability, which
defaults to `local` only — so nobody publishes to production by accident before
you've decided who may. Define it in `AppServiceProvider`:

```php
Gate::define('manageKinetixAnnouncements', fn ($user) => $user->isAdmin());
```

An entry with **no publish date is a draft** and a **future date schedules it**;
neither reaches a reader's feed until its moment arrives. The list shows drafts
and scheduled entries — the reader endpoints never do.

With teams on, a platform-wide entry (`team_id` NULL) is **read-only inside a
team**: it belongs to every tenant, so editing it from one team would rewrite
the message for all of them. Edit those outside a team scope, or from code.

---

## Publishing from code

Publish entries from a seeder, a deploy step, a command — anywhere:

```php
use Happones\Kinetix\Announcements\KinetixAnnouncements;

KinetixAnnouncements::publish(
    'Dark mode is here 🌙',
    'Toggle it from the header — your choice is remembered across devices.',
    'feature',                       // 'info' (default) | 'feature' | 'fix'
);

// Schedule one for later:
KinetixAnnouncements::publish('Maintenance window', '…', 'info', now()->addDay());
```

Only entries with a past `published_at` are shown; a `null` value is a draft.

### Multi-tenant

With `kinetix.teams` on, an announcement belongs to the team it was published
from, and a `NULL` team is **platform-wide** — shown in every team's feed. A
user's feed is their team's entries plus the global ones; another team's are
never visible.

```php
// Scoped to the team the request is serving.
KinetixAnnouncements::publish('New export format', '…');

// Platform-wide — every tenant sees it. This is what a deploy step or seeder
// wants, and what `publish()` falls back to anyway when there is no team
// context (no request, no currentTeam).
KinetixAnnouncements::publishGlobally('v2.0 is here 🎉', '…', 'feature');
```

Upgrading an existing install:

```bash
php artisan vendor:publish --tag=kinetix-announcements-migrations --force
php artisan migrate
```

Additive and idempotent — existing entries keep `team_id` NULL, so they stay
platform-wide and every feed keeps showing them. The same publish brings the
per-team read state, the banner's dismissals table and the feed index; nothing
re-appears as unread after the upgrade.

---

## The component

Mount the "What's new" trigger in your app header:

```vue
<script setup lang="ts">
import KinetixAnnouncements from '@/components/kinetix/KinetixAnnouncements.vue';
</script>

<template>
    <KinetixAnnouncements />
</template>
```

It shows a megaphone icon with an **unread badge**; opening the popover lists the
published feed (new entries get a dot + the badge clears by marking the feed
seen). `useKinetixAnnouncements()` exposes `{ announcements, unread, loading,
load, markSeen }` for a custom UI. Strings are localized (`announcements_*`,
7 locales).

---

## The banner

The popover waits to be opened — fine for a changelog, useless for something the
user has to read. `<KinetixAnnouncementBanner>` puts the message inside the page
instead: one entry at a time, rotating through the rest when there is more than
one.

<Screenshot name="announcement-banner" alt="Announcement banner with carousel controls" />

```vue
<script setup lang="ts">
import KinetixAnnouncementBanner from '@/components/kinetix/KinetixAnnouncementBanner.vue';
</script>

<template>
    <!-- Everything is optional: these are the defaults. -->
    <KinetixAnnouncementBanner
        :limit="3"
        :autoplay="8000"
        dismissible
    />

    <!-- Only what matters, no rotation clock: -->
    <KinetixAnnouncementBanner :levels="['feature', 'fix']" :autoplay="0" />
</template>
```

| Prop              | Default      | What it does                                            |
| ----------------- | ------------ | ------------------------------------------------------- |
| `limit`           | `3`          | How many entries rotate (server ceiling: 10)            |
| `levels`          | all          | Restrict to these levels                                |
| `autoplay`        | `8000`       | Rotation interval in ms; `0` turns auto-rotation off    |
| `dismissible`     | `true`       | Show the close button                                   |
| `position`        | `inline`     | `inline` or `fixed-top` (see below)                     |
| `fixedWidthClass` | `max-w-3xl`  | Width of the pinned bar                                 |
| `class`           | —            | Merged onto the alert surface                           |

### Pinned to the top

`position="fixed-top"` pins the banner to the top of the viewport instead of
leaving it in the page flow — for the announcement everyone has to see, whatever
page they're on. Mount it once in your layout:

<Screenshot name="announcement-banner-fixed" alt="Announcement banner pinned to the top of the viewport" />

```vue
<KinetixAnnouncementBanner position="fixed-top" :levels="['info']" />
```

It sits above the page and **below** Kinetix's own overlays, so a modal or a
dropdown still covers it. Because a pinned bar hides whatever is under it, the
component publishes its measured height (it changes as entries wrap) as
`--kinetix-announcement-banner-height` on `<html>` — reserve the space in your
layout and get it back the moment the banner is dismissed:

```css
.app-shell {
    padding-top: var(--kinetix-announcement-banner-height, 0px);
}
```

**Dismissing is per announcement**, unlike the popover's single "I read the
feed" timestamp: closing a banner hides *that* entry for that user, on every
device, and leaves the unread badge alone. Rotation pauses on hover and on
keyboard focus, has an explicit pause button, and is turned off entirely for
users who ask their OS for reduced motion — who still get the arrows and dots.
Left/right arrow keys move between entries.

`useKinetixAnnouncementBanner({ limit, levels })` exposes
`{ announcements, loading, load, dismiss }` if you'd rather build your own.

The default `limit` comes from config when the component doesn't pass one:

```php
'announcements' => [
    'enabled'      => env('KINETIX_ANNOUNCEMENTS_ENABLED', true),
    'banner_limit' => env('KINETIX_ANNOUNCEMENTS_BANNER_LIMIT', 3),
],
```

---

## What it costs to mount

Both components used to fetch on mount, so a header that's re-created on every
page (an Inertia layout that isn't persistent) meant **one request per
navigation**, for a feed that changes maybe weekly.

Kinetix now ships the unread count and the banner feed on every Inertia response
as `kinetix_announcements`:

- The header trigger renders its badge straight from the payload and fetches the
  list **only when the popover is opened** — once, not on every open. A header
  nobody clicks costs zero requests.
- The banner renders from the payload too, unless you narrow it with `levels` or
  a `limit` that differs from `announcements.banner_limit` — then only the
  server can answer, so it fetches.

```php
'announcements' => [
    'share' => env('KINETIX_ANNOUNCEMENTS_SHARE', true),
],
```

Turn `share` off and the payload is `null`; both components fall back to
fetching for themselves, exactly as before. That's the trade: a couple of
indexed queries per Inertia response, or a round-trip per component mount.

---

## How "unread" works

Each user has a **last-seen** timestamp per team, plus one for the platform-wide
entries. An announcement is *new* to them when its `published_at` is later than
the timestamp of the pool it belongs to (everything is new until they first open
the feed). Opening the popover updates both, so:

- reading team A's feed never clears team B's badge — the two teams track their
  own state;
- the platform-wide entries are read **once**, not once per team.

Dismissing a banner is separate and per announcement: it hides that entry from
that user's banner without touching the unread count.

---

## Endpoints

Registered under your Kinetix prefix (`{current_team}/_kinetix/announcements` with teams on).
The feed is scoped to the active team plus the platform-wide entries:

| Method   | Route                                 | Name                            | Gated by |
| -------- | ------------------------------------- | ------------------------------- | -------- |
| `GET`    | `{prefix}/announcements`              | `kinetix.announcements.index`   | auth |
| `GET`    | `{prefix}/announcements/banner`       | `kinetix.announcements.banner`  | auth |
| `POST`   | `{prefix}/announcements/seen`         | `kinetix.announcements.seen`    | auth |
| `POST`   | `{prefix}/announcements/{id}/dismiss` | `kinetix.announcements.dismiss` | auth |
| `GET`    | `{prefix}/announcements/manage`       | `kinetix.announcements.manage`  | `manageKinetixAnnouncements` |
| `POST`   | `{prefix}/announcements`              | `kinetix.announcements.store`   | `manageKinetixAnnouncements` |
| `PUT`    | `{prefix}/announcements/{id}`         | `kinetix.announcements.update`  | `manageKinetixAnnouncements` |
| `DELETE` | `{prefix}/announcements/{id}`         | `kinetix.announcements.destroy` | `manageKinetixAnnouncements` |

`index` returns the published feed (each with an `isNew` flag) plus the `unread`
count; `banner` returns the published entries the user hasn't dismissed
(`?limit=`, `?levels=feature,fix`); `seen` marks the feed read; `dismiss` hides
one entry. An id from another tenant is a 404 — `dismiss` resolves through the
same team-scoped query the feed uses.

`manage` returns the authoring list (drafts and scheduled entries included, each
with a `status` and `isGlobal`) plus `teamScoped`; `store`/`update` take
`title`, `body`, `level` and a nullable `published_at`. Deleting an
announcement also deletes its dismissals.
