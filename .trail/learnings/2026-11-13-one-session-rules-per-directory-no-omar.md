---
title: "One session, rules per directory: no project subagents"
author: Omar
date: 2026-11-13
branch: main
cost: { minutes: 6, tokens: 172853 }
id: l-7cb91109
scope: repo
status: active
src: agent
touches:
  - CLAUDE.md
  - src/CLAUDE.md
  - resources/js/CLAUDE.md
  - tests/CLAUDE.md
  - database/CLAUDE.md
  - docs/CLAUDE.md
  - docs/.vitepress/config.ts
  - CHANGELOG.md
---

## Decided
- The user dropped the project subagents (architect/backend/frontend/reviewer in `.claude/agents/`, removed in v0.291.1). One general session plans, implements, reviews, verifies and releases.
- Their rules moved next to the code as area `CLAUDE.md` files, loaded when working in that directory: `src/` (PHP, API, tenancy, security, queries), `resources/js/` (Vue, a11y, bundle, contract), `tests/` (testbench, engine recipe, vitest gotchas), `database/` (migrations), `docs/` (public docs + Boost skills). Root `CLAUDE.md` keeps repository-wide rules and the index.
- The `kinetix-workflow` skill now describes the single-session passes; the planning template and the review checklist (severities, team isolation, contracts) live in `.claude/skills/kinetix-workflow/references/`.
- The review is still a separate pass before every tag, written down as findings by severity; for security- or tenancy-heavy changes an independent general-purpose agent may read the diff + checklist.

## Tried and ruled out
- Keeping the four role subagents: the user prefers one general session that handles everything, with the rules kept in their directories.

## Watch out
- `CLAUDE.md` files outside the root ship in the Packagist archive unless export-ignored: `.gitattributes` uses a slashless `CLAUDE.md export-ignore` (any depth) plus `/.claude export-ignore`.
- VitePress turns every `.md` under `docs/` into a page: `docs/CLAUDE.md` is listed in `srcExclude`.
- `resources/js/CLAUDE.md` sits outside the published subfolders (components, composables, stores, plugins, icons, types/kinetix.ts), so it never reaches a host app. Don't put an area file inside a published folder.
- Area files don't name the parity framework; only the root `CLAUDE.md` states that rule by name.
