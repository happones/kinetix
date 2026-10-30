---
title: "Import modes: create / upsert / update / replace (deactivate the list)"
author: Omar
date: 2026-10-30
branch: main
cost: { minutes: 20, tokens: 242848 }
touches:
  - AGENTS.md
  - CHANGELOG.md
  - docs/import-export.md
  - gallery/messages.en.json
  - gallery/specimens.ts
  - resources/boost/skills/kinetix-import-export/SKILL.md
  - resources/js/components/KinetixImporter.vue
  - resources/js/composables/useKinetixImporter.ts
  - resources/js/types/kinetix.ts
  - resources/lang/en/kinetix.php
---

## Decided
- Modes are opt-in per importer (`$importModes`); empty keeps the legacy path (rows go through resolveRecord() unchanged). One mode = applied, still shown in the review step.
- The mode travels inside `ImportOptionsData` (`mode`), not as a new ImportProcessor constructor arg: a promoted property added later would be uninitialized on jobs already serialized in the queue.
- Mode is applied in `Importer::applyMode()` after prepareRow and BEFORE validation, so "already exists"/"no match" is the reason reported, not a unique-rule error. It uses the memoized record().
- Replace is an end-of-job sweep, not "deactivate all first": snapshot the keys active at begin, keep every matched key (even if its row later fails), deactivate snapshot − kept at the end, and skip entirely when 0 rows imported. A dead job or an all-failed file never empties the list.
- Deactivation: SoftDeletes first, else `$activeColumn` / `active` / `is_active`; bulk query (no model events), overridable `deactivate()`. Reactivation is set on the record (not saved) so importRow()'s single save persists it.
- `query()` is THE tenant seam for matchBy lookups and the sweep; `authorizeMode()` checked at start.

## Tried and ruled out
- Adding a third param to getCompletedNotificationBody(): breaks host overrides (fewer params = incompatible signature). The deactivated count is appended by the job instead.
- `withTrashed()` on `Builder<Model>`: PHPStan rejects it; use `withoutGlobalScope(SoftDeletingScope::class)`.
- Viewport `sm:` grid for the mode cards: the dialog doesn't follow the screen; container query `@md:` like ImporterOptions.

## Watch out
- New tests that run ImportProcessor need `queue.default=sync` in defineEnvironment: under `testbench package:test` the completion notification is queued and hits a missing `jobs` table (phpunit alone is green).
- Upsert/Update without `$matchBy` or a resolveRecord override, or Replace on a model with neither SoftDeletes nor an active column, throws LogicException when settings() runs (dialog open) — on purpose.
