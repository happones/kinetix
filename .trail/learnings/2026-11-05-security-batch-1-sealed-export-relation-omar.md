---
title: "Security batch 1: sealed export relation scope, billing authorization, sanitizer"
author: Omar
date: 2026-11-05
branch: main
cost: { minutes: 55, tokens: 249090 }
touches:
  - AGENTS.md
  - CHANGELOG.md
  - config/kinetix.php
  - docs/billing.md
  - docs/confidential.md
  - docs/forms.md
  - docs/import-export.md
  - docs/permissions.md
  - docs/relation-managers.md
  - docs/reports-center.md
---

## Decided
- An export/report `relation` scope only counts as a sealed value: `RelationScope::seal()` (Crypt, `purpose => kinetix-export-relation`, bound to the exporter class). Anything else exports 0 rows. The check sits at the sink (`Exporter::resolveExportQuery`), because stored rows replay through retry, run-now, the scheduler and queued jobs.
- Relation methods are resolved through the @internal `Support\EloquentRelation::resolve()`. It requires a public, non-static method with no required params, not declared by any `Illuminate\` class or trait; a declared return type must be a Relation. Only then does it call the method and require `instanceof Relation`. The cost: framework trait relations (`Notifiable::notifications()`) are refused.
- Reports Center: a report's own `authorize()` gates the type list, launch, schedule, run-now, retry, runs list, download, cancel and the scheduler. The dispatch identity (creator / launcher) is judged inside the run's team.
- Spatie team context for an out-of-request check goes through ONE helper, `Permissions\PermissionTeam::run($teamId, $fn, $user)`: pin, unset roles/permissions, restore in finally. Billing, membership, the permission controller and the scheduler use it.
- Billing authorization order: `billing.authorize` callback → the billable is the user → the Gate (`billing.view`/`billing.manage`, evaluated in the billed team) → with no permissions module, view for members and manage for `ownsTeam()`. Checks live at the HTTP boundary only.
- The basic rich editor sanitizes with its own allowlist DOM rebuild (`useKinetixHtmlSanitizer`, no DOMPurify dependency) on mount, watch, paste and outside drops. Internal drags are left to the browser.
- Confidential: unwrapped DEKs live only in an in-process memo with a TTL; the cache holds wrapped rows under `v2` keys. Forms withhold confidential attributes like hidden ones (blank = keep).

## Tried and ruled out
- `Model::isRelation()` as the relation check: it is true for `delete`.
- Stripping `relation` only at the controllers: poisoned stored rows still fire.
- A Gate-only billing check: it denies everyone in apps without spatie.
- Judging the scheduler's authorize() without pinning the team: team-role users' schedules silently stop (the reviewer caught it).

## Watch out
- Any new endpoint that runs exports/reports must go through `RelationScope::open`. Never call `$model->{$name}()` with a client-influenced name.
- `kinetix:make-billing` has no `--force`; it always overwrites the page.
- 5 controllers still call `$parent->{$relationName}()` on server-signed names (TableWrite, RelationManager, Bulk, FormAction, TableRepeater): batch 2 candidates for `EloquentRelation`.
