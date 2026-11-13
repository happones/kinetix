# Review checklist

The review is the last gate before an immutable tag. Read the complete diff as if someone else wrote it, judge whether the solution is right for an Open Source package that others will maintain and extend, and be ready to send it back. Passing tests are not approval.

## Order of priority

1. Security
2. Correctness
3. Data integrity, authorization and team isolation
4. Architecture fit
5. Performance
6. Maintainability and extensibility
7. Backward compatibility
8. Tests
9. Developer experience
10. Style, only when it has a practical impact

## Severity

| Severity | Meaning | Before the tag |
|---|---|---|
| CRITICAL | Exploitable security issue, cross-team data exposure, data loss | must fix |
| HIGH | Functional defect, authorization weakness, serious architecture or performance problem | must fix |
| MEDIUM | Correctness, compatibility or maintainability concern with real impact; any broken project contract | must fix |
| LOW | Limited-risk improvement | fix if cheap and safe, else record |
| INFO | Optional | record |

Each finding: severity, file:line, the problem, a concrete failure or attack scenario, and the fix. No scenario, no finding. Verify every claim against the code and the lowest dependency versions.

## Security

- Authorization enforced server-side on every endpoint and action, at object level (IDOR), including bulk and cross-page bulk.
- Endpoint tokens via `SignedDescriptor` (user + team route key + expiry); nothing the client sends widens what the token sealed.
- Mass assignment, SQL injection (raw fragments, column names from input, `orderByRaw`), XSS (`v-html`, Markdown, URLs), CSRF, path traversal, file uploads, unsafe deserialization.
- Sensitive data in props, logs, events, exceptions or exports; information leaks in error messages.
- Insecure defaults; privilege escalation through roles, tokens or impersonation.

## Team isolation (multi-team by default)

- Team resolved with `KinetixTeams::keyFor()` / `currentTeam()` (membership-checked), never `$user->currentTeam`.
- Data scoped with `ScopedToTeam` or an explicit team column; an unresolvable team yields nothing, never everything. A route prefix is not isolation.
- Queued jobs, commands, schedules, broadcasts, webhooks and anything without a request carry the team key explicitly.
- Cache keys, per-user state and frontend storage are keyed by team (and user).
- The teams-off mode (single tenant) still works.

## Correctness and contracts

- Data classes in `src/Data` and `resources/js/types/kinetix.ts` match field by field (optional fields nullable on both sides).
- Config callbacks are callable arrays (closures break `config:cache`).
- Published files never claim a host filename; a changed published file is marked **(published)** in the CHANGELOG.
- APIs exist at the lowest allowed version: Laravel 12.0, inertia-laravel 2.0, testbench 10.0, PHP 8.3.
- Engine-specific SQL behaves on MySQL, MariaDB, PostgreSQL (types: `bigint ~~ text`, int4 bounds) and SQLite.
- Every user-facing string in every locale, vue-i18n `{named}` placeholders.
- Every connected file updated (`trail callers <symbol> --depth all`); `trail check` against the learnings.

## Architecture and maintainability

- Fits the module boundaries and the plan; deviations justified in the plan file.
- No new abstraction without a concrete need; no duplicated primitive (Kinetix components, composables, shared PHP helpers).
- Public API intentional, named consistently with its siblings, documented (docs page + Boost skill), backward compatible or with an upgrade note.

## Performance

- Queries per request, N+1, unbounded queries, missing indexes for new filters and sorts.
- Hydration and serialization size; partial reloads and deferred props used where they help.
- Frontend: reactivity cost, bundle growth (bundle guard specs), lazy loading of heavy dependencies.
- Separate demonstrated problems from risks; ask for a measurement when static reading cannot settle it.

## Frontend and accessibility

- The REQUIRED accessibility rules of the development skill: labelled controls, keyboard paths, focus management, live-region announcements, 24px targets, reduced motion, phone width.
- Kinetix primitives and `--kinetix-z-*` variables; shadcn/reka style.
- Loading, empty and error states; frontend restrictions never treated as authorization.

## Tests

- Behaviour, edge cases, failure paths, authorization, validation, team isolation, regression of the bug fixed.
- Tests prove behaviour, not implementation details; no vacuous spies or assertions that pass on the old code.
- Queue-dispatching tests fake the queue or set `queue.default`; timing-dependent JS tests freeze `Date.now`.

## Release hygiene

- CHANGELOG entry with the right sections and upgrade notes; no Filament mention in public text; no Co-Authored-By trailer.
