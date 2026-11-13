# Testing rules (`tests/`)

Tests prove behaviour a host app relies on: public APIs, authorization, team isolation, validation, serialization and failure paths. A test that passes on the old, broken code proves nothing.

## Layout

| Path | What |
|---|---|
| `tests/Feature` | PHPUnit feature tests under Orchestra Testbench, in-memory SQLite |
| `tests/Unit` | pure PHP units |
| `tests/Integration` | real-engine tests (MySQL, MariaDB, PostgreSQL); skipped unless `KINETIX_DB_INTEGRATION` is set |
| `tests/Concerns`, `tests/Fakes` | shared fixtures (`ResolvesTeamRoutes`, `CountsKinetixQueries`, `CreatesPermissionTables`…); reuse before writing a new one |
| `tests/js` | vitest + @vue/test-utils + happy-dom specs, including the scan/guard specs (bundle, z-index, mobile, contrast, gallery stubs) |

PHPUnit, not Pest. Test classes extend `Happones\Kinetix\Tests\TestCase`.

## What every change needs

- The behaviour, edge cases and failure paths; the bug's regression case.
- Authorization: the forbidden path, not only the allowed one.
- Multi-team: a second team that must not see or touch the first team's data, the unresolvable-team case (fails closed), and the teams-off mode.
- Contracts: Data payload shape for anything the frontend reads.
- Query counts (`CountsKinetixQueries`) when the change is about performance.

## PHP gotchas

- Run with `vendor/bin/testbench package:test`, not `phpunit`: the testbench skeleton boots config differently (`phpunit.xml` env does not apply).
- Anything that dispatches (queued notifications, jobs) must `Queue::fake()` or set `queue.default` to `sync` in `defineEnvironment`; otherwise it fails on a missing `jobs` table only under `package:test`.
- No session in a plain test request: assert flashes across two requests.
- Use APIs available at the lowest versions (Laravel 12.0, testbench 10.0); prefer-lowest runs in CI.

## Engine integration tests

For SQL that differs between engines (casts, `LIKE` on non-text columns, timeouts, full-text):

1. `docker network create kx-engines`, then start `mysql:8.4`, `mariadb:11` and `postgres:16-alpine` on that network with **no published ports**.
2. Image `kinetix-ci-php83-engines` = `kinetix-ci-php83` + `install-php-extensions pdo_mysql pdo_pgsql`.
3. In a detached worktree with a fresh prefer-lowest vendor (the host vendor fails `platform_check` on PHP 8.3), run `vendor/bin/testbench package:test tests/Integration` with `KINETIX_DB_INTEGRATION=mysql|mariadb|pgsql`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
4. Run timing-based tests several times; calibrate slow predicates per engine (see the learnings). Stop the containers and remove the network afterwards.

## JS gotchas

- Specs of a controlled component feed `update:*` events back through `setProps`.
- i18n components need the i18n plugin in `global.plugins`.
- A spec that depends on two events falling in different milliseconds is flaky in CI: freeze `Date.now`. To delete a cookie in happy-dom, set `expires=Thu, 01 Jan 1970 00:00:00 GMT` (`max-age=0` can survive).
- `rm -f vendor/orchestra/testbench-core/laravel/vendor` before vue-tsc or eslint; testbench recreates that symlink and it makes the tools loop.

## Scope

While iterating, run only the affected tests (`--filter=`, a single spec). The full suites run before every tag (see the `kinetix-workflow` skill).
