---
name: kinetix-workflow
description: "How Kinetix work is planned, implemented, reviewed, verified and released by a single session. Activate for any feature, module, public API change, refactor, bug fix or release in the Kinetix package, when a plan or a review is due, and before committing, pushing or tagging."
---

# Kinetix workflow

One session owns a change from the user's request to the pushed tag. The discipline that used to come from separate roles now comes from separate passes: plan, implement, review, verify, release. Do not skip a pass because the same session wrote the code.

## 1. Decide the path

| Change | Path |
|---|---|
| New feature or module, public API (PHP/TS/config/Data), behaviour change, cross-cutting refactor, anything touching tenancy or security | plan → implement → review → verify → release |
| Small local bug fix with an obvious cause | implement → review → verify → release |
| Docs or skill text only | edit → `trail check` → commit |

When unsure, plan. The review pass is never skipped for code that will be tagged.

## 2. Plan

Follow `references/planning.md` and save the plan in the session scratchpad; it is the contract the implementation and the review are checked against.

- Start with `trail ask "<task>" --source` and read the learnings it surfaces; run `trail callers <symbol> --depth all` before planning a change to a symbol.
- Verify reported bugs at runtime before writing a Fixed entry (reproduce first, on a real engine when the bug is engine-specific).
- Questions only the user can answer go to the user, few and concrete, before implementation starts. Record their decisions in the plan.
- Check `git ls-remote --tags origin` before assigning version numbers. Split a large plan into releases that each ship whole.

## 3. Implement

- Contract first: Data classes in `src/Data` and `resources/js/types/kinetix.ts` change together, then the PHP behaviour, then the Vue side. Follow the area rules in `src/CLAUDE.md`, `resources/js/CLAUDE.md`, `tests/CLAUDE.md`, `database/CLAUDE.md` and `docs/CLAUDE.md`.
- Tests go in with the code, not after it. Run the scoped checks as you go (the area files list them).
- Large mechanical work that splits cleanly (a sweep over many files, an independent audit) can go to a general-purpose agent with a precise brief; the session still owns the design, the review and the release. Never let two agents or sessions edit the same working tree for different releases.
- A deviation from the plan is written into the plan file with its reason, so the review sees it.

## 4. Review

Before verifying, switch from author to reviewer: read the complete diff (`git diff`, plus `git status` for new files) against the plan and `references/review-checklist.md`, as if someone else wrote it.

- Write the findings down (scratchpad), each with severity, file:line, failure scenario and fix. A finding without a concrete scenario is not a finding.
- CRITICAL/HIGH/MEDIUM are fixed before the tag; LOW/INFO are fixed when cheap and safe, otherwise recorded as follow-ups. Tags are immutable, so fix before tagging rather than after.
- Re-check every fix, and check that a fix does not regress something else (e.g. dropping a modifier's ordering to isolate an `orWhere`).
- For security-sensitive or tenancy-heavy changes, an independent read helps: give a general-purpose agent the diff and the checklist, not your conclusions, and verify its claims before acting on them.

## 5. Verify (before every tag)

1. `vendor/bin/testbench package:test` — the full suite (not `phpunit`; there is no paratest, so no `--parallel`).
2. PHP 8.3 prefer-lowest in docker:
   - `git worktree add --detach <scratchpad>/wt HEAD`, then copy the uncommitted and new files into it.
   - `docker run --rm --user $(id -u):$(id -g) -e HOME=/tmp -v <wt>:/app -w /app --entrypoint bash kinetix-ci-php83 -c "composer update --prefer-lowest --prefer-dist -n && vendor/bin/testbench package:test"`.
   - `kinetix-ci-php83` is php:8.3-cli plus `install-php-extensions gd intl zip bcmath pdo_sqlite @composer`. Never use `--ignore-platform-req`: it changes the resolution.
3. `composer analyse`.
4. `vendor/bin/pint --test`, twice (lang files included).
5. `rm -f vendor/orchestra/testbench-core/laravel/vendor`, then `npx vue-tsc --noEmit` and the full `npx vitest run`.
6. Engine-specific SQL: `tests/Integration` against real MySQL 8.4, MariaDB 11 and Postgres 16 (recipe in `tests/CLAUDE.md`).
7. `trail check`; explain false positives in the commit message.

Remove the worktree and stop every container afterwards.

## 6. Release

- CHANGELOG `## [x.y.z] - date` with Added/Changed/Fixed/Security, **(published)** marks on files hosts publish, and upgrade notes (never advise `--force` on config).
- Commit on main (no Co-Authored-By trailer), push, tag, push the tag, confirm with `git ls-remote --tags origin`.
- Save a trail learning when the work took real digging (`trail note`), and commit it with the change.
- Report to the user in Spanish: what shipped, what was verified (with numbers), what is pending, and decisions needed.

## 7. Non-negotiables

- **Multi-team by default:** a tenant is a team ("empresa"). Every plan, change and review states how teams are isolated, and that the teams-off mode still works.
- Floors (`^12|^13`, inertia-laravel `^2|^3`) checked at the lowest version; config callbacks as callable arrays; Data ↔ TS in step; every locale, with vue-i18n `{named}` placeholders; shadcn/reka + a11y rules; no Filament mentions in public docs, skills or published JS comments; a docs page and Boost skill for public API changes.
