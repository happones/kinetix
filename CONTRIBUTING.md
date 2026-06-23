# Contributing to Kinetix

Thanks for your interest in improving Kinetix! Contributions of all kinds are
welcome — bug reports, fixes, features, docs and translations.

## Ground rules

- Be respectful — see the [Code of Conduct](CODE_OF_CONDUCT.md).
- For security issues, **do not open a public issue** — see [SECURITY.md](SECURITY.md).
- Open an issue to discuss anything large before sending a big PR.

## Project layout

Kinetix is a Laravel package (PSR-4 `Happones\Kinetix\` → `src/`) with a Vue 3 +
Inertia front end under `resources/js/`. There is no Sail/Docker here — it is the
package repo, tested with [Testbench](https://github.com/orchestral/testbench).

- PHP backend: `src/`, tested in `tests/` (Pest/PHPUnit via Testbench).
- Vue/TS front end: `resources/js/`, tested with Vitest.
- TypeScript data interfaces in `resources/js/types/index.ts` are **hand-maintained**
  to mirror the spatie/laravel-data DTOs — keep them in sync when you touch a DTO.
- Per-feature docs live in `docs/`. Keep them current with behavior changes.
- Translations: `resources/lang/{en,es,fr,pt}/kinetix.php` — keep all locales in parity.

## Setup

```bash
composer install
npm install
```

## Running the checks

All four must pass before a PR is merged (CI enforces them):

```bash
# PHP tests
vendor/bin/testbench package:test

# Static analysis (PHPStan / Larastan, level 5)
vendor/bin/phpstan analyse --memory-limit=1G

# PHP code style (Laravel Pint)
vendor/bin/pint --test        # check; run `vendor/bin/pint` to fix

# Front-end unit tests + type check
npx vitest run
npx vue-tsc --noEmit
```

## Pull requests

1. Branch from `main`.
2. Add or update tests for your change — every change must be programmatically tested.
3. Run all checks above.
4. Add a `## [Unreleased]` entry to [CHANGELOG.md](CHANGELOG.md); mark **(published)**
   if it changes a file consumers publish (components, types, lang, config).
5. Keep PRs focused; describe the motivation and any breaking changes.

## Coding conventions

- Match the surrounding code — naming, structure, comment density.
- PHP: typed signatures, constructor property promotion, PHPDoc array shapes.
- Vue: single root element; style with shadcn semantic tokens (never raw palette
  classes); build interactive widgets on Reka UI; do not import the host's
  `@/components/ui/*`.
