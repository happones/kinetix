---
title: "Export relation columns: eager loads derived from dot paths, keyset paging, to-many lists"
author: Omar
date: 2026-10-30
branch: main
cost: { minutes: 6, tokens: 44318 }
touches:
  - AGENTS.md
  - CHANGELOG.md
  - docs/import-export.md
  - resources/boost/skills/kinetix-import-export/SKILL.md
  - src/Exports/ExportColumn.php
  - src/Exports/Exporter.php
  - src/Exports/Jobs/ExportProcessor.php
  - src/Reports/ReportRunner.php
  - src/ReportsCenter/Jobs/ReportRunJob.php
  - ".kiro/settings/mcp.json"
---

## Decided
- Dot-notation already worked via data_get but lazy-loaded per record (N+1, and threw under preventLazyLoading). `Exporter::eagerLoads()` derives relation paths from column names by walking the model: a segment counts only if the model itself declares a public, zero-arg method (not on Model) that returns a Relation; the walk stops at the first non-relation (accessor / JSON key). Plus overridable `with()` for formatStateUsing reads and constrained loads.
- `Exporter::eachChunk()` is the single iteration path (ExportProcessor, ReportRunner, ReportRunJob): eager loads + `chunkById` (qualified key) when query() has no orders/unions, else `chunk()` to keep the user's order.
- ExportColumn walks the path itself: model → model by segment; an Enumerable (to-many) maps the rest over every item and joins with `->separator()` (default ', '), `->distinct()` optional; once the target is a plain value/array the remaining path goes to data_get in one call, so JSON wildcards keep working.

## Tried and ruled out
- Calling `isRelation()` / any method blindly to detect relations: could invoke non-relation methods with side effects; restricted to methods declared below Model.

## Watch out
- A to-many path used to export empty (data_get on a Collection with a key returns null); now it is a joined string — formatStateUsing receives the joined string.
- Key paging changes order only for exports with no explicit order (they were unordered SQL anyway).
