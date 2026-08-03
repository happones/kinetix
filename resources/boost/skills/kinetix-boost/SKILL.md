---
name: kinetix-boost
description: Best practices for Kinetix development using Laravel Boost, including searching documentation, using database context tools, running PHPStan/Pint, Laravel 13 / PHP 8.3+ requirements, and adapting Kinetix migrations to UUID/ULID user, team, or morph-target models.
license: MIT
metadata:
  author: happones
---

# Kinetix Boost Best Practices

## When to Apply

Activate this skill when:
- Seeking project-scoped docs or general Laravel/Vue details using `search-docs`.
- Inspecting database schemas, indexes, and tables with `database-schema`.
- Interacting with database data via `database-query`.
- Resolving app URLs or logs via Boost tools.
- Checking PHP 8.3+ or Laravel 13 compatibility.

---

## 1. Boost Tool Best Practices

### A. Searching Documentation
- **Always use `search-docs` first** when starting a new task or coding logic.
- Pass a `packages` array when you want to filter results to specific packages like `inertiajs/inertia-laravel` or `spatie/laravel-data`.
- Keep search terms focused on the functionality (e.g., `test resource table` rather than `filament 4 test resource table`).

### B. Database Inspection
- Prefer the **`database-schema`** tool to query database structures before creating or writing migrations.
- Use **`database-query`** for read-only queries instead of writing raw SQL in Tinker.

### C. URLs & Logs
- Use **`get-absolute-url`** to resolve correct paths and ports for URLs before giving them to the user.
- Use **`browser-logs`** for quick inspection of frontend/console errors.

---

## 2. Kinetix Specific Tooling & Quality Rules

### A. Code Style & Linting
- **Pint**: Always format modified PHP files before finishing changes using:
  ```bash
  composer format
  ```
- **PHPStan**: Always run static analysis check before committing changes using:
  ```bash
  composer analyse
  ```
  *(Note: Run with `--memory-limit=-1` if default runs hit PHP memory limits).*

### B. Dependency Guidelines (PHP 8.3+ & Laravel 13)
- The project requires PHP 8.3 or superior. Ensure syntax remains fully compatible with PHP 8.3+ (e.g. constructor property promotion, explicit return type declarations).
- Verify compatibility with Laravel 13 on all updates.
- Keep `illuminate/support` and Testbench compatibility aligned with the core targets.

### C. Testing
- Run tests via `composer test` to confirm everything remains green.
- Do not add arbitrary debugger code, tinker scripts, or manual route files when unit/feature tests are available.

## 3. UUID / ULID Host Models (users, teams, or any referenced model)

Kinetix migrations build every column that references a HOST model
(`user_id`, `team_id`, morph ids like `commentable_id`/`taggable_id`/
`subject_id`/`causer_id`, and `invited_by`/`created_by_id`/`launched_by_id`)
with `Happones\Kinetix\Support\HostKeys`, which **types the column after the
app's model at migrate time**: `HasUlids` → `ulid`, `HasUuids` → `uuid`, a
string `$keyType` → `string`, anything else → `unsignedBigInteger`. The team
model derives from the user's `teams` relation. Mixed apps just work — each
column follows the model it points to.

What an agent must still do in a UUID/ULID app:

1. Check the actual key types first — the `database-schema` tool, or the
   model's `HasUuids`/`HasUlids` trait / `$keyType`. Never assume bigint.
2. **Morph targets can't be detected.** If commented/tagged/audited models use
   UUID/ULID keys, set `kinetix.key_types.morph` (`KINETIX_MORPH_KEY_TYPE`)
   BEFORE `php artisan migrate`.
3. If detection can't see the setup (unconventional auth provider, no `teams`
   relation at migrate time), pin `kinetix.key_types.user` / `.team`
   explicitly — a pinned value beats detection.
4. Columns pointing at Kinetix's OWN tables (`tag_id`, `webhook_endpoint_id`,
   `parent_id`, …) stay `unsignedBigInteger` — never retype them.
5. No FK rewiring is needed (plain indexed columns), EXCEPT
   `kinetix-permission-team-migrations`, which adds real FKs to
   spatie/laravel-permission pivots — apply spatie's own UUID guidance there.
6. Tables migrated on an older Kinetix have bigint columns on disk: write an
   `ALTER` migration in the app; integer→UUID data conversion is manual.

The per-feature column list lives in the docs: Installation → "UUID / ULID
primary keys on your models".
