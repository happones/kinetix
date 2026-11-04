---
title: Scoped owner bypass and platform-level abilities (v0.273.0)
author: Omar
date: 2026-11-04
branch: main
cost: { minutes: 58, tokens: 141357 }
touches:
  - AGENTS.md
  - CHANGELOG.md
  - config/kinetix.php
  - docs/impersonation.md
  - docs/membership.md
  - docs/permissions.md
  - resources/boost/skills/kinetix-impersonation/SKILL.md
  - resources/boost/skills/kinetix-membership/SKILL.md
  - resources/boost/skills/kinetix-permissions/SKILL.md
  - resources/js/composables/useKinetixCan.ts
---

## Decided
- `permissions.owner_bypass` takes a structured form: `['using' => true|callable, 'only' => null|patterns, 'except' => patterns]`. Patterns are a feature name, `feature.*` or an exact key, never globs. Granted = (only ?? all registered) − except − platform − unregistered.
- Bool and callable values still work as the legacy form. The ownership callback stays `($user, $team)`.
- The owner `Gate::before` and `dynamicallyGrantedPermissions()` share one pure predicate (`TeamOwner::grants()` / `filter()`), so server and SPA agree by construction. A parity-matrix test pins this, and the prop costs 0 Gate calls for an owner.
- `Feature::platform()` marks abilities that never follow ownership; `users.impersonate` is one.
- Platform abilities are grantable or revocable on roles only by a super-admin (`PermissionController::assertCanGrant`). Roles carrying one are assignable in Members only by a super-admin (`MembershipController::withoutPlatformRoles`, which fails closed). `kinetix:doctor` warns about assigned roles that already carry one.
- The seeder's admin/editor presets no longer get platform abilities.

## Tried and ruled out
- Passing the ability to the ownership callback: N callback calls per page load, and what owners get can't be inspected.
- A `grantsUsing()` hook to add prop grants: a second source of truth that still needs a host Gate::before.
- An impersonation recipe that lets every non-owner cross teams: a team-scoped role holding `users.impersonate` launders cross-tenant impersonation. Only a super-admin may cross teams.

## Watch out
- `impersonation.can_impersonate` REPLACES the built-in guards. A callback must start with `ImpersonationManager::passesBuiltInGuards()`.
- Under spatie teams, `hasRole()` misses a teamless super-admin inside a team request; use `SuperAdmin::check()`.
- Any host `Gate::define` for an ability makes `assertCanGrant` (which asks the Gate) let its holders hand it out. Keep platform checks independent of the Gate.
- Built-in impersonation still has no team limit for stored-row holders: the root fix (keep non-super-admins inside the URL team) is an open follow-up.
- Modules with `kinetix.{module}.teams = false` are app-wide but not marked platform yet. That is documented only (put them in `except`), so the code follow-up is still open.
- Pint re-aligns `=>` in the 7 lang files when a longer key is added.
