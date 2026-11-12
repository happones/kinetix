---
name: kinetix-workflow
description: "How Kinetix work is planned, implemented, reviewed, verified and released with the agent team (kinetix-architect, kinetix-backend, kinetix-frontend, kinetix-reviewer). Activate for any feature, module, public API change, refactor, bug fix or release in the Kinetix package, when writing a brief for one of the agents, when a reviewer returns findings, and before committing, pushing or tagging."
---

# Kinetix workflow

The main session is the orchestrator: it scopes the work, writes the briefs, merges the reports, runs the verification and releases. The agents in `.claude/agents/` do the planning, the implementation and the review.

## 1. Decide the path

| Change | Path |
|---|---|
| New feature or module, public API (PHP/TS/config/Data), behaviour change, cross-cutting refactor, anything touching tenancy or security | architect → backend/frontend → reviewer |
| Small local bug fix with an obvious cause | backend and/or frontend → reviewer |
| Docs or skill text only | edit directly → reviewer optional, `trail check` required |

When unsure, use the architect. The reviewer is never skipped for code that will be tagged.

## 2. Plan (`kinetix-architect`)

Brief it with: the user's goal in their words, what is already verified (files and lines), what is ruled out, constraints, and the decisions the user already made. Ask for: verdicts on reported bugs (with evidence), decisions with alternatives, the public API, config keys, a task list split into backend/frontend with dependencies, phases/versions, risks, acceptance criteria and tests, upgrade notes for host apps.

- Questions the plan raises that only the user can answer go to the user (few and concrete) before implementation starts.
- Save the approved plan in the session scratchpad and point the implementers at it, instead of re-typing it.
- Check `git ls-remote --tags origin` before assigning version numbers.

## 3. Implement (`kinetix-backend`, `kinetix-frontend`)

- Run both in parallel when the contract (Data classes ↔ `resources/js/types/kinetix.ts`, payload shapes, i18n keys) is fixed in the plan; otherwise backend first.
- Each brief names: the plan file and the task ids, the files the OTHER agent owns (don't touch), "do not commit/push/tag", files never to touch (`.gitignore`, `AGENTS.md`, `.ignore`, `.kiro/`, untracked `.trail/` files), the checks to run, and what to report (files, tests, deviations, CHANGELOG bullets, exact i18n strings).
- One owner per shared file at a time (CHANGELOG, `config/kinetix.php`, lang files, docs): give it to one agent and leave a marker for the other.
- Resume an agent that already has the context (SendMessage) for follow-ups and review fixes instead of starting a new one.
- Never run two sessions or agents that edit the same working tree for different releases at the same time: the next release starts after the previous one is committed.

## 4. Review (`kinetix-reviewer`)

- Point it at the uncommitted diff, the plan, the focus areas (security, tenancy, contracts, floors, a11y, tests) and the known deviations.
- **APPROVED** → release. **APPROVED WITH WARNINGS** → fix what is cheap and safe before the tag (tags are immutable), record the rest. **CHANGES REQUESTED** → send the findings to the implementers, then a focused re-review of the fixes.
- Check the reviewer's claims that change behaviour before acting on them; challenge a fix that would cause a regression (e.g. dropping a modifier's ordering to isolate an `orWhere`).

## 5. Verify (before every tag)

1. `vendor/bin/testbench package:test` (full suite; there is no paratest — no `--parallel`).
2. PHP 8.3 prefer-lowest: a detached worktree in the scratchpad with the uncommitted files copied in, then `docker run ... kinetix-ci-php83 ... vendor/bin/testbench package:test` (recipe in the verify-with-CI notes).
3. `composer analyse`.
4. `vendor/bin/pint --test` twice (lang files included).
5. `rm -f vendor/orchestra/testbench-core/laravel/vendor`, then `npx vue-tsc --noEmit` and the full `npx vitest run`.
6. Engine-specific SQL: run the integration tests against real MySQL/MariaDB/Postgres containers on a private docker network — never publish host ports (check `~/.claude/bin/cccwebenvdev-ports` before any port).
7. `trail check`; explain false positives in the commit message.

## 6. Release

- CHANGELOG `## [x.y.z] - date` with Added/Changed/Fixed/Security, **(published)** marks and upgrade notes (never advise `--force` on config).
- Commit on main (no Co-Authored-By trailer), push, tag, push the tag, confirm with `git ls-remote --tags origin`.
- Save a trail learning when the work took real digging; commit it with the change.
- Report to the user in Spanish: what shipped, what was verified (with numbers), what is pending, and decisions needed.

## 7. Non-negotiables in every brief

- **Multi-team by default:** a tenant is a team ("empresa"). Every plan, implementation and review states how teams are isolated (see the development skill).
- Laravel 12 / inertia-laravel 2 floors; config callbacks as callable arrays; Data ↔ TS in step; 7+ locales with vue-i18n `{named}` placeholders; shadcn/reka + a11y rules; no Filament mentions in public docs, skills or published JS comments; a docs page and Boost skill for public API changes.
