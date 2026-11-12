---
title: Search modes and the PostgreSQL type traps
author: Omar
date: 2026-11-12
branch: main
cost: { minutes: 39, tokens: 260847 }
touches:
  - ".agents/skills/kinetix-development/SKILL.md"
  - AGENTS.md
  - CHANGELOG.md
  - config/kinetix.php
  - docs/forms.md
  - docs/search.md
  - docs/tables.md
  - resources/boost/skills/kinetix-forms/SKILL.md
  - resources/boost/skills/kinetix-search/SKILL.md
  - resources/boost/skills/kinetix-tables/SKILL.md
---

## Decided
- `SearchMode` (Contains/Prefix/Exact/FullText) on columns, Table, Select/SelectFilter (sealed descriptor `mode`, absent = Contains) and `KinetixQuery::search` map form. Plain and associative string lists keep working (values are column names); only SearchMode/Closure values are specs, anything else throws.
- PostgreSQL `LIKE` on non-text columns errors (`bigint ~~ text`, reproduced on PG 16): Contains/Prefix cast the int key, int/real/float/double/decimal/date casts, `getDates()` timestamps and native uuid to text, on pgsql only. Text and ULID columns are never cast (keeps prefix indexes).
- Exact never errors: the int key binds `cast(? as bigint)` on pgsql; other integer columns use a plain bind bounded to int4 (a cast column may be varchar); dates use a strict round-trip parse (Y-m-d[ H:i[:s]]), never `strtotime`; floats must be finite. A column the term can't match is skipped; all skipped → `1 = 0`.
- `using` closures run inside the grouped OR and count as applied only if they added a where (an empty one would widen to every row).

## Tried and ruled out
- `cast(? as bigint)` for every integer column: breaks `integer`-cast varchar columns on pgsql.
- `strtotime` as the date guard: accepts '2026', 'monday', '+1 week', which PG rejects.

## Watch out
- `using` closures keep only where constraints (joins/orders are dropped by the nested group).
- Uncast integer columns and `smallint` past 32767 are not guarded on pgsql: cast them in the model or use `searchable(using:)`.
