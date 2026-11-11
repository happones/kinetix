---
title: "Notification routing: routable types, fallbacks and role labels per team"
author: Omar
date: 2026-11-11
branch: main
cost: { minutes: 97, tokens: 664336 }
touches:
  - ".agents/skills/kinetix-development/SKILL.md"
  - AGENTS.md
  - CHANGELOG.md
  - config/kinetix.php
  - docs/membership.md
  - docs/notification-routing.md
  - docs/permissions.md
  - resources/boost/skills/kinetix-membership/SKILL.md
  - resources/boost/skills/kinetix-notification-routing/SKILL.md
  - resources/boost/skills/kinetix-permissions/SKILL.md
---

## Decided
- Types declare `routable(false)`, `availableWhen(cb)`, `fallbackDescription()`, `fallbackUsing(cb)` on KinetixNotificationRouting::type(). Unavailable types: hidden, store/recipients 422, update 404, and saved rules ignored at send (fallback applies) — screen and send stay symmetric.
- availableWhen/fallbackUsing always get the REAL team key (null only when the app has no teams), even when `notification_routing.teams` is off; scopeKey() is only for storing/querying rules. Fallback users are intersected with the team's members inside PermissionTeam::pin.
- Host callbacks are shape-checked at registration (no class built at boot) and resolved with ConfigCallback::resolve on every call, so no host instance outlives a request (Octane/queues).
- Role labels: `permissions.role_label` callback → translated presets (role_preset_*) → null (UI falls back to the name); memoized per request + team. Routing audiences exclude only SuperAdmin::role().

## Tried and ruled out
- Resolving callbacks once at registration into the singleton registry: keeps request/team state alive across requests.
- Passing scopeKey() to availability: null for platform-scoped routing in team apps, so the screen offered types the send then ignored.
- Excluding all protected_roles from audiences: "all admins" is a valid audience.

## Watch out
- A role_label callback must return null for roles it doesn't label, or it disables the default label.
- Test all three modes: teams on, teams off, app teams on + notification_routing.teams=false.
