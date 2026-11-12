---
title: "Tables at scale: cached totals, per-phase budget, partial reloads"
author: Omar
date: 2026-11-12
branch: main
cost: { minutes: 38, tokens: 107319 }
touches:
  - ".agents/skills/kinetix-development/SKILL.md"
  - AGENTS.md
  - CHANGELOG.md
  - config/kinetix.php
  - docs/performance.md
  - docs/relation-managers.md
  - docs/search.md
  - docs/tables.md
  - resources/boost/skills/kinetix-search/SKILL.md
  - resources/boost/skills/kinetix-tables/SKILL.md
---

## Decided
- `Table::cachedTotal()` key = connection + database + team segment + user + impersonator + count SQL + prepared bindings. Teams on with no resolved team = never read or write the cache (keep `enabledFor` + `currentTeamKey()`; `KinetixTeams::keyFor` can't tell "teams off" from "unresolved").
- Never cache a total of 0: `Builder::paginate(..., $total = 0)` skips the row query, so a cached 0 hides new rows until the TTL ends.
- One `QueryBudget::run` per phase (count, rows, aggregates): on Postgres a cancelled statement aborts the budget's transaction. Count timeout → simple paging; rows timeout → `timedOut`, no rows; aggregates → empty + `timed_out`. No budget when the table has no stats/summarizers.
- `KinetixTable reloadOnly` is opt-in; relation managers forward theirs (a behaviour change for hosts that already set it). `kinetix_toast` / `kinetix_flash` are `Inertia::always`; `kinetix_alerts` is NOT (it decrements showings per evaluation).

## Tried and ruled out
- Model-event invalidation of totals, engine row estimates, `Cache::flexible`.

## Watch out
- Engine test slow predicates: `BENCHMARK(30000, MD5(...))` is ~10 ms/row on MySQL 8.4 / MariaDB 11; 100000 (~40 ms/row) made the 11-row page flake past the 700 ms budget.
- The engine tests need the `kinetix-ci-php83-engines` image (kinetix-ci-php83 + pdo_mysql/pdo_pgsql) on a private docker network.
