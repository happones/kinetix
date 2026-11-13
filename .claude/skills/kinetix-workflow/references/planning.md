# Planning a Kinetix change

Think like the maintainer of an Open Source package for the next five to ten years: optimize for maintainability, security, compatibility and developer experience over implementation speed. Plan within the existing architecture, not around it.

## Before writing the plan

1. `trail ask "<task>" --source`; read the learnings it surfaces (decided / ruled out / watch out).
2. Read the code that will change and its callers (`trail callers <symbol> --depth all`).
3. Look for an existing module, primitive or extension point before proposing a new one: grep the module first. Kinetix has 50+ modules; most "new" needs are an option on an existing one.
4. Check the lowest allowed version of every dependency API you plan to use (Laravel 12.0, inertia-laravel 2.0, testbench 10.0).
5. Reproduce reported bugs. A plan states verdicts with evidence: confirmed (how), not reproducible, or working as designed.

## Plan template

Save as `<scratchpad>/<topic>-plan.md`.

1. **Goal** — the user's request in their words, and what is out of scope.
2. **Findings** — what the code does today, with file:line; verdicts on reported bugs.
3. **Decisions** — for each decision with long-term consequences, at least two approaches with trade-offs, and the recommendation. Mark the ones the user must make.
4. **Public API** — PHP methods and signatures, config keys with defaults, Data fields and their TS mirror, events, i18n keys (with the English text), published files.
5. **Multi-team** — how each new query, cache key, job, event, token, setting and UI is isolated per team; what happens when the team cannot be resolved (fail closed); the teams-off behaviour.
6. **Security** — trust boundaries, authorization (server-side, object level), signed descriptors, validation, what reaches the frontend.
7. **Performance** — queries per request, indexes, payload size, bundle impact; what needs a measurement rather than an assumption.
8. **Tasks** — ordered, small, independently verifiable; backend before frontend where the contract depends on it.
9. **Tests and acceptance criteria** — the behaviour each test proves, including failure, authorization, team-isolation and engine cases.
10. **Release** — version(s), CHANGELOG sections, breaking changes, upgrade notes for host apps.
11. **Risks and follow-ups.**

## Principles

- Laravel-native first: providers, policies and gates, Form Requests, events, jobs, config publishing. Do not build an internal framework where Laravel already has one.
- Composition over inheritance; explicit contracts; small public surface. Every public extension point is intentional, documented and stable.
- The backend is the source of truth for authorization and business rules; frontend visibility is presentation only.
- No abstraction without a concrete second use; no optimization without a measured or clearly unbounded cost.
- Backward compatible by default. A break needs a reason, a CHANGELOG "Changed" entry and an upgrade note.
- Record significant decisions as a trail learning (decided / ruled out / watch out) when the release ships.
