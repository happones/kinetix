---
title: "Member roles: follow the Roles screen, owner-only super-admin, app-defined roles"
author: Omar
date: 2026-10-27
branch: main
cost: { minutes: 19, tokens: 238728 }
touches:
  - ".agents/skills/kinetix-development/SKILL.md"
  - AGENTS.md
  - CHANGELOG.md
  - config/kinetix.php
  - docs/membership.md
  - docs/public/screenshots/member-list-dark.png
  - docs/public/screenshots/member-list.png
  - gallery/messages.en.json
  - gallery/stubs/http.ts
  - resources/boost/skills/kinetix-membership/SKILL.md
---

## Decided
- `membership.assignable_roles` defaults to `null`: with permissions on, the member picker offers `AssignableRoles::names($teamId)` (team + global roles); without it, `['editor','viewer']`. Configs published before v0.262 keep the fixed list and are NOT linked; kinetix:doctor reports which one is in use.
- `MembershipController::assignableRoles()` always strips `SuperAdmin::protectedRoles()`, whatever the source. Super-admin belongs to the team owner and is never a member role.
- Member roles don't have to be spatie roles. Entries can be `['name','label','description']`, and `membership.assign_role` ([Class,'method'], `($user, ?$to, ?$from, $provision)`) takes every role write through `KinetixMembership::syncRole()`. Never call assignRole/removeRole for members directly.
- The index returns `roles`, `identifier_field`, `reserved_roles` and `can`. The UI hides actions the viewer can't take, and keeps a non-assignable current role listed but locked (KinetixSelect `disabledKeys`).

## Tried and ruled out
- Filtering roles that don't exist in the DB out of the allow-list: it breaks callbacks that return roles created at request time. Doctor warns about missing roles instead.

## Watch out
- KinetixMemberList used to send `{email}` whatever `membership.identifier` said, so username/phone provisioning got a 422. It also dropped the one-time credential that direct/manual mode returns. Both are fixed now; keep `provision()` posting under `identifierField`.
- In a flex-col, `basis-*` sets HEIGHT. Put basis/flex-1 behind the row breakpoint (`@xl:basis-56`), or a stacked form gets huge gaps.
- The members card lays out with container queries (`@container`, `@3xl:`, `@xl:`): it is often mounted in a narrow column, where viewport breakpoints lie.
