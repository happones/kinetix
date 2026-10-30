---
title: "Import relationship columns: id or name, batched per chunk, tenant-scoped"
author: Omar
date: 2026-10-30
branch: main
cost: { minutes: 8, tokens: 59397 }
touches:
  - AGENTS.md
  - CHANGELOG.md
  - docs/import-export.md
  - gallery/messages.en.json
  - resources/boost/skills/kinetix-import-export/SKILL.md
  - resources/lang/en/kinetix.php
  - resources/lang/es/kinetix.php
  - resources/lang/fr/kinetix.php
  - resources/lang/ja/kinetix.php
  - resources/lang/pt/kinetix.php
---

## Decided
- `ImportColumn::relationship($relation, $matchBy)` for BelongsTo (sets FK) and BelongsToMany (`->multiple(',')`, synced AFTER save via `syncRelationships()`); anything else throws LogicException, which fails each row visibly.
- Resolution runs after validation (`processRow`: prepare → mode → validate → resolveRelationships → importRow); castStateUsing runs on the cell BEFORE the lookup, and importRow does not cast relationship columns again. Not-found / ambiguous throw ValidationException keyed by the column so the failed-rows CSV shows the column label.
- Batching: ImportProcessor maps the chunk once, calls `warmRelationships()` (distinct cells, one query per matchBy column, 500 values per IN), and the per-import cache stores found / missing (null) / ambiguous (false), bounded at 20k. Un-warmed cells (prepareRow changed them) resolve on demand the same way.
- Key column only tried with values that can be a key (int key → ctype_digit), so "Bebidas" never hits `id`.
- Case-insensitive by default: `whereIn(col, exact) OR whereIn(LOWER(col), lowered)` — the exact branch covers SQLite's ASCII-only LOWER().
- createMissing OFF by default (user's choice). Created keys are tracked per row and forgotten on row failure, because the savepoint rolls the record back.

## Tried and ruled out
- Per-row lookups in castStateUsing (the old workaround): one query per row, no context for tenancy, silent nulls.

## Watch out
- A mutation test confirmed the rollback-forget logic is what makes `createMissing` safe; without it later rows reference a rolled-back id (SQLite even reuses the id, hiding it unless you count rows).
- Default matchBy is the related key only; docs tell hosts to put `name` first when names look numeric.
