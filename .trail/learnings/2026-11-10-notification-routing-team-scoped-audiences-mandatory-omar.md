---
title: "Notification Routing: team-scoped audiences, mandatory channels, fail-closed members"
author: Omar
date: 2026-11-10
branch: main
cost: { minutes: 53, tokens: 251794 }
touches:
  - AGENTS.md
  - CHANGELOG.md
  - README.md
  - config/kinetix.php
  - docs/.vitepress/config.ts
  - docs/gdpr.md
  - docs/installation.md
  - docs/notification-preferences.md
  - docs/notifications.md
  - docs/permissions.md
---

## Decided
- Notification Routing reuses `NotificationTypeRegistry` as the platform's notification list; routing only adds per-type `requires()` (ability judged at SEND time) and `mandatory()` (code only, overrides the user's opt-out on those channels; never from a screen or endpoint).
- No external contacts (user decision): recipients are team users only. Line/WhatsApp alerts are phase 2 and must NOT be mirrored into the Inbox by default.
- Member identity is an opaque ref `{morphClass}|{key}` everywhere (rules, groups, purge, preview) — key-only ids confuse User#5 with Client#5 and become persisted data.
- Routed `database` deliveries go through `TeamStampedDatabaseChannel`, otherwise `NotificationScope` shows the row in every team's bell.
- Eligibility runs inside `PermissionTeam::pin($teamKey)` so `KinetixTeams::resolveCurrent()` and `TeamOwner` judge the routing team, not the user's current team, in the worker.

## Tried and ruled out
- `KinetixTeams::members()` falling back to `User::query()->limit(500)` when the team can't be resolved: leaked one team's alert to another team's users (reviewer reproduced). Routing passes `failClosed` and reaches nobody; doctor errors.
- Intersecting with a capped 500-member window in no-teams apps: holders past the window were silently never notified. Open set loads holders by key and reads `eligible` with `lazyById`.
- Two screens each owning their own composable refs: deleting a group left 404-ing rules on the other screen. One ref-counted store per route prefix.

## Watch out
- Laravel's `NotificationSender::sendNow($n, $notif, [])` treats an empty channel list as "use via()": never pass `[]`, skip the recipient.
- `Gate::has()` is false for spatie / registry abilities granted through `Gate::before`; doctor must also accept those.
- `Cache::lock` throws on stores without `LockProvider` (Octane store): guard it.
- Integer-key models + a crafted ref (`User|abc`) 500 on Postgres unless the key is validated first.
- New lang keys must be pint-aligned (`vendor/bin/pint resources/lang`), or `pint --test` fails CI.
