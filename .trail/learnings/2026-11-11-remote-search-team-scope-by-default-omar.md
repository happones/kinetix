---
title: "Remote search team scope by default: mixed tables, modifiers and schema cache"
author: Omar
date: 2026-11-11
branch: main
cost: { minutes: 44, tokens: 86408 }
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
- `search.team_scope` (default on in team apps) bounds the search endpoint, selected labels, every Select/CheckboxList/Radio relationship() (eager options + strict validation), SelectFilter/MultiSelectFilter preloaded options and the default RelationManager::getRelatedQuery(). Opt-out `withoutTeamScope()`, sealed in the encrypted descriptor.
- Order in SearchScope::bindToTeam: teams/config off → none; explicit constraint on the team column (or the role model's team key) wins; ScopedToTeam → forCurrentTeam(), or forCurrentTeamOrGlobal() only when the model declares `kinetixTeamRowsIncludeGlobal()` (Announcement, MailTemplate) or the field opts in with `teamScopeIncludesGlobal()`; spatie Role model → AssignableRoles visibility (team + global); table with `search.team_column` → that team; no column → none. No resolved team → `1 = 0`.
- Modifiers (closure or class) run through SearchScope::modify() as a global scope: wheres grouped, first boolean forced to `and` but the `not` of whereNot kept, joins/orders kept, and the caller's limit cap wins.
- hasColumn: in-process memo (flushed by KinetixStateReset) + cache store entry with `search.schema_cache_ttl` (3600), version bumped on MigrationsEnded.

## Tried and ruled out
- Grouping with `$builder->where(fn ...)`: drops joins/ordering.
- Forcing the group boolean to plain `and`: inverts whereNot modifiers.
- forCurrentTeamOrGlobal() for every ScopedToTeam model: 6 of 8 Kinetix models use NULL for platform/no-team rows (ApiLog, NotificationGroup/Route, ReportRun/Schedule, AnnouncementView).
- rememberForever for the schema check: fails open on other nodes/workers after a migration adds team_id.

## Watch out
- A resource (or base resource) that overrides getEloquentQuery() is no longer auto-bound in relation pickers: bound it with SearchScope::bindToTeam().
- Mixed tables (team + shared NULL rows) need the model hook or the field opt-in.
