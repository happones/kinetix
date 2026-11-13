# Migration rules (`database/`)

Kinetix migrations are not loaded automatically: hosts publish them by tag (`vendor:publish --tag=kinetix-<module>-migrations`) and own them afterwards. A published migration can never be changed in place; a schema change is a new migration.

- **Name:** `2026_01_01_0000NN_<verb>_<table>.php`, with the next free number (check `ls database/migrations` and the remote tags' versions). Tables are prefixed `kinetix_`.
- **Publish:** register the file under its module's tag in `KinetixServiceProvider` (the same `publishes()` map the module already uses), mark it **(published)** in the CHANGELOG, and add an upgrade note telling hosts to publish and migrate.
- **Teams:** team-owned tables get a team column (nullable only where shared global rows are intended, with that behaviour documented) and an index that leads with it for the module's main lists. Never key uniqueness without the team.
- **Indexes:** every new filter, sort or lookup path has an index plan; composite indexes follow the query's equality columns first, then the sort.
- **Portability:** the schema must work on MySQL, MariaDB, PostgreSQL and SQLite. Watch column types that differ (json, uuid/ulid, unsigned integers, text length) and defaults that some engines reject.
- **Reversible:** `down()` undoes `up()`; guard alterations of host tables (`users`, permission tables) with `Schema::hasColumn` checks.
- **Tests:** feature tests run the migration they need (`require` the file and call `up()`, as the fixtures in `tests/Concerns` do), so a broken migration fails the suite.
- **Doctor:** when a module cannot work without its tables or columns, `kinetix:doctor` reports how to publish and migrate them.
