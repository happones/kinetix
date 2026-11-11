---
title: Webhooks fail closed without a team; channel phone countries
author: Omar
date: 2026-11-11
branch: main
cost: { minutes: 20, tokens: 51466 }
touches:
  - ".agents/skills/kinetix-development/SKILL.md"
  - AGENTS.md
  - CHANGELOG.md
  - config/kinetix.php
  - docs/channels.md
  - docs/webhooks.md
  - resources/boost/skills/kinetix-channels/SKILL.md
  - resources/boost/skills/kinetix-webhooks/SKILL.md
  - resources/js/components/Channels/KinetixChannelLinker.vue
  - resources/lang/de/kinetix.php
---

## Decided
- With teams on, `KinetixWebhooks::fire()` delivers only when the team comes from a routed request or a PermissionTeam pin; otherwise it skips and logs the event name (`webhooks.unresolved_team` = skip | platform). `fireFor(null)` stays the explicit platform path; jobs, scheduler and console use `fireFor($team, ...)`.
- Outside a routed request the auth user's currentTeam is never used: queue workers don't reset auth guards, so a job that logged a user in would leak later events to that user's team.
- `channels.phone_countries` (ISO alpha-2, app-wide): the payload lists only those, a default outside the list is dropped, link() rejects other dial codes with a 422 on `phone`; a non-array or all-invalid value fails closed and `kinetix:doctor` warns.
- KinetixChannelLinker default: configured → browser region → US → first listed (the country list is sorted by name: first = Afghanistan).

## Tried and ruled out
- Falling back to the first listed country: preselects Afghanistan on default installs.
- Telling apps to just delete a dispatcher wrapper that made fireFor(null) skip: upstream fireFor(null) still means platform, so their null-team calls would leak.

## Watch out
- In tests, a direct `fire()` after `$this->get()` resolves the team from that last request.
- Kinetix processors (imports, exports, reports, flows) don't pin their team yet: host hooks there must use fireFor.
