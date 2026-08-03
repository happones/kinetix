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

## Publishing

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
platform-wide and every feed keeps showing them.

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
en/es/fr/pt).

---

## How "unread" works

Each user has a single **last-seen** timestamp. An announcement is *new* to them
when its `published_at` is later than that timestamp (everything is new until
they first open the feed). Opening the popover updates the timestamp, so the
badge reflects only entries published since their last visit.

---

## Endpoints

Registered under your Kinetix prefix (`{current_team}/_kinetix/announcements` with teams on).
The feed is scoped to the active team plus the platform-wide entries:

| Method | Route                          | Name                          |
| ------ | ------------------------------ | ----------------------------- |
| `GET`  | `{prefix}/announcements`       | `kinetix.announcements.index` |
| `POST` | `{prefix}/announcements/seen`  | `kinetix.announcements.seen`  |

`index` returns the published feed (each with an `isNew` flag) plus the `unread`
count; `seen` marks the feed read for the current user.
