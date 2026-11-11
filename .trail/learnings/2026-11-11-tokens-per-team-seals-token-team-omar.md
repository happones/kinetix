---
title: "Tokens per team: seals, token-team pin and middleware priority"
author: Omar
date: 2026-11-11
branch: main
cost: { minutes: 60, tokens: 108193 }
touches:
  - ".agents/skills/kinetix-development/SKILL.md"
  - AGENTS.md
  - CHANGELOG.md
  - config/kinetix.php
  - docs/tokens.md
  - resources/boost/skills/kinetix-tokens/SKILL.md
  - src/Api/Middleware/LogApiRequest.php
  - src/Billing/BillingManager.php
  - src/Commands/DoctorCommand.php
  - src/Comments/Comment.php
---

## Decided
- A token belongs to a team by the existing `tokens.team_column` or the new `tokens.team_ability` seal (`team:{key}`); `KinetixTokens::teamOf()` reads the column first, then the stored abilities (never tokenCan — `*` can everything) and ignores values that are catalog scopes. Clients can't send seal/stamp abilities (422); `stamp_abilities` are added; TokenData hides both. `create_middleware` applies to POST only so revoking survives a downgrade.
- `kinetix.token-team` pins the token's team (PermissionTeam::pin with the user, dropping loaded spatie roles) for the request: billing (BillingManager + memo key), entitlements (memo key with pinnedKey), currentRouteKey and streamed bodies follow it; a URL team segment must be the token's team (403) and the holder must be a member (404/403).
- Kernel priority (KinetixServiceProvider::prioritizeTeamMiddleware, uses only get/setMiddlewarePriority — Laravel 11.0-safe): ONLY EnsureTokenTeam goes before SubstituteBindings; after binding: SetPermissionsTeam → EnsureEntitled → spatie Role/Permission/RoleOrPermission → Authorize. Without SubstituteBindings: token team after auth, readers before Authorize.
- HostKeys::userModelClass() is the single user-model resolver (falls back to the web guard's provider when the default guard, e.g. sanctum, has none).

## Tried and ruled out
- `addToMiddlewarePriorityBefore()`: added in Laravel 11.27 — fatal on boot for 11.0–11.26.
- Putting the team readers before SubstituteBindings: they jumped ahead of `kinetix.permissions.team`, so role:/permission: ran with no spatie team id → 403 on session routes in every app.

## Watch out
- Tokens issued to non-members (devices, field staff) must not go through kinetix.token-team.
- afterResponse/terminate/queued work runs unpinned: pass the team explicitly.
- Composer ≥2.9 blocks advisory-affected versions: prefer-lowest installs Laravel 12.69, not 11 — the ^11 floor isn't really installable or tested.
