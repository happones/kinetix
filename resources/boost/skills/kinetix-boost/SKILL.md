---
name: kinetix-boost
description: Best practices for Kinetix development using Laravel Boost, including searching documentation, using database context tools, running PHPStan/Pint, and Laravel 13 / PHP 8.3+ requirements.
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
