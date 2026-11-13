# Backend rules (`src/`)

Kinetix runs as a dependency inside other people's Laravel applications. Every class here is either public API that hosts build on, or an internal that must stay out of their way. Deliver Laravel-native code that a host developer can use, extend and debug without reading the package source.

Also binding: the REQUIRED "Server Performance Rules" and "Endpoint Security Rules" and the module sections in `.agents/skills/kinetix-development/SKILL.md`.

## Code style

- `declare(strict_types=1)`, constructor promotion, explicit parameter and return types, array-shape PHPDoc, curly braces always, PHPDoc over inline comments, TitleCase enum cases.
- Small focused classes; dependency injection over service location in hot paths; immutable value objects where state does not change.
- No abstraction without a concrete need; do not rebuild what Laravel already provides.
- Pint re-aligns a multi-line `&&` condition or a `match (true)` arm with comparisons on every run, so `pint --test` never passes: write those as separate `if`s or early returns.
- Comments and PHPDoc never name other admin frameworks (see the root `CLAUDE.md`).

## Public API

- A public method, config key, event, Data field or published file is a contract: intentional, named like its siblings, documented (`docs/` page + Boost skill in `resources/boost/skills/`), and backward compatible. A break needs a CHANGELOG "Changed" entry and an upgrade note.
- Adding a parameter to an overridable public method breaks subclasses that redefine it: say so in the upgrade notes.
- Keep internals internal (`@internal`, private, or final where extension makes no sense).
- Config callbacks are callable arrays (`[Class::class, 'method']`), never closures: closures break `config:cache`.
- Use only APIs that exist at the lowest allowed version (Laravel 12.0, inertia-laravel 2.0). Probe newer ones with `method_exists` and keep a working fallback.

## Multi-team (REQUIRED)

A tenant is a team ("empresa" to the user and consuming apps). Every module, endpoint, query, job, cache key, event, notification, webhook, token, setting and UI is designed for many teams from day one.

- Resolve the team with `KinetixTeams::keyFor('<module>')` / `currentTeam()` (membership-checked), never `$user->currentTeam`.
- Scope data with `Support\Concerns\ScopedToTeam` or an explicit team column. An unresolvable team yields nothing, never everything. A route prefix is not isolation.
- Queued jobs, commands, schedules, broadcasts and webhooks carry the team key explicitly; there is no request to resolve it from.
- Cache keys and per-user state are keyed by (team, user).
- The teams-off mode (single tenant) keeps working; test both modes.

## Security

- All input is untrusted. Authorize server-side, at object level, on every endpoint and action (bulk included); hidden UI is not security.
- Endpoint tokens go through `Support\SignedDescriptor` (user + team route key + expiry). Nothing the client sends may widen what the token sealed; mark intentionally unsealed inputs with `#[Unsealed]`.
- No raw SQL built from input; column names from the client are checked against an allow-list. Guard `$fillable` / `$guarded`.
- Never serialize Eloquent models to the frontend: go through a Data class with only the fields the UI needs.
- Secrets never reach props, logs, events, exceptions or exports.

## Backend ↔ frontend contract

- Payloads are Spatie Data classes in `src/Data`, mirrored by hand in `resources/js/types/kinetix.ts`. Change both in the same commit; grep the TS file for the type name first (duplicate interfaces merge silently).
- Optional Data fields are nullable on both sides. Keep payloads small: partial reloads (`only`), deferred props, and `Inertia::always` only for props that must survive a partial reload.

## Queries and performance

- No N+1: eager load what the serializer touches. Paginate and filter in the database, never in memory.
- Every new filter or sort has an index plan (see `database/CLAUDE.md`).
- Engine differences are real: PostgreSQL rejects `LIKE` on non-text columns (`bigint ~~ text`) and int4 overflows; MySQL and MariaDB differ in session timeouts. Engine-specific SQL needs an integration test in `tests/Integration`.
- Resolve relations with `Support\EloquentRelation::resolve`, not by calling arbitrary methods named by input.
- Nothing hits the database during boot. Cache only when the invalidation path is understood and wired.

## Strings and config

- Every user-facing string goes into every locale under `resources/lang/` (8 today); placeholders use `:name` in PHP and `{name}` once they reach vue-i18n.
- New config keys go into `config/kinetix.php` with a comment and a safe default, plus a `kinetix:doctor` check when a wrong value is dangerous.

## Checks while working

- `vendor/bin/testbench package:test --filter=<Test>` (scoped), `composer analyse` (always the full run: a partial path only reports unmatched ignore patterns), `vendor/bin/pint --test`.
- Find code with `trail ask "<task>" --source` / `trail grep "<literal>"`; run `trail callers <symbol> --depth all` before changing a symbol.
