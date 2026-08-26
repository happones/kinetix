---
name: kinetix-help
description: "In-app manual from markdown files: permission-gated articles, server-side search, screenshots, and a fully localized reading experience (per-locale variants, language-aware endpoints, untranslated markers). Activates when writing help articles, mounting KinetixHelpCenter/KinetixHelpArticle, translating the manual, or wiring help screenshots."
license: MIT
metadata:
  author: happones
---

# Kinetix Help Center Development

## When to Apply

Activate this skill when:
- Writing or organising markdown articles in the help directory
  (`kinetix.help.path`, default `resources/help`).
- Mounting `<KinetixHelpCenter>` / `<KinetixHelpArticle>` or using
  `useKinetixHelp`.
- Translating the manual (`{slug}.{locale}.md` variants, coverage, fallbacks).
- Gating articles or blocks by permission, or wiring help screenshots.

## Documentation

For full details, reference `docs/help-center.md` (published at https://happones.github.io/kinetix/help-center).

## Configuration

```php
'help' => [
    'enabled'           => env('KINETIX_HELP_ENABLED', false),
    'path'              => env('KINETIX_HELP_PATH'),          // null = resource_path('help')
    'show_route'        => 'help.show',                       // Spotlight link target
    'locales'           => null,                              // null = Locale module's, else on-disk variants
    'fallback_locale'   => env('KINETIX_HELP_FALLBACK_LOCALE'), // language of the base `.md` files
    'hide_untranslated' => env('KINETIX_HELP_HIDE_UNTRANSLATED', false),
    'cache'             => [
        'enabled'  => env('KINETIX_HELP_CACHE', false),
        'ttl'      => env('KINETIX_HELP_CACHE_TTL', 3600),
        'strategy' => env('KINETIX_HELP_CACHE_STRATEGY', 'fingerprint'), // fingerprint | ttl
    ],
    'screenshots' => [ /* disk, path_prefix, pages, credentials, selectors */ ],
],
```

## Authoring

One markdown file per article in the help directory; the slug is the filename
minus `.md` (an `NN-` prefix orders naturally). Optional FLAT front matter
(no nested YAML): `title`, `group`, `icon`, `order`, `permission`.
`README.md` and `_*.md` are ignored (drafts).

```md
---
title: Products
group: Catalog
icon: package
order: 2
permission: products.view
---

# Products

![Product list](screenshots/products.png)

<!-- kinetix:can products.delete -->
## Deleting a product
Only users allowed to delete products read this.
<!-- /kinetix:can -->
```

Gating is enforced server-side: a gated ARTICLE disappears from the index and
search and its URL 404s (never 403 — existence isn't leaked); a gated BLOCK is
stripped from the markdown before rendering. Rendering uses
`html_input=strip` + `allow_unsafe_links=false`.

## Translations (first-class)

- Variants live next to the base file: `02-products.es.md`. The base `.md` must
  exist — variants alone aren't discovered.
- Resolution: `{slug}.pt_BR.md` → `{slug}.pt.md` → `{slug}.{fallback_locale}.md`
  → `{slug}.md`. Titles, excerpts, groups, ordering AND search all read the
  resolved file.
- **Locale is part of the request**: every endpoint takes `?locale=` (validated
  against `help.locales` — unsupported input is ignored, so it can't pick files
  or widen cache keys), responses carry `Content-Language` + `Vary`, and the
  components send the app's active language. Switching language re-fetches the
  index and the open article — no reload, no stale copy.
- Payloads carry `locale` + `isFallback` (+ `availableLocales` on an article),
  so untranslated entries are marked with the language they're really in and
  rendered with that `lang` attribute. `hide_untranslated` removes them
  entirely instead (index, search and a 404 on their URL).
- Per-article chips let a reader open one article in another language without
  changing the app locale (`hide-language-switcher` to disable).

```bash
php artisan kinetix:help-status                      # coverage matrix article × locale
php artisan kinetix:help-status --strict             # CI gate: fails on any gap
php artisan kinetix:make-help-page --locale=es --from=02-products
php artisan kinetix:make-help-page --locale=es       # every missing variant
```

## Screenshots

`![Alt](screenshots/name.png)` embeds are rewritten to the authenticated
streaming route (private disks work). Capture with
`php artisan kinetix:help-screenshots` (Playwright runner published via
`--tag=kinetix-help-screenshots`); add `--locale=es` to store a localized set
under `{path_prefix}/{locale}/` — articles in that language get it, everything
else falls back to the shared capture. Committed PNGs under
`{help.path}/screenshots/[{locale}/]` work with zero setup.

**Images do NOT belong in git, and by default they aren't**: captures go to a
disk, the local PNGs are deleted after upload (`--keep-local` opts out), the
default `public` disk path is already covered by Laravel's
`storage/app/public/.gitignore`, and the command's scratch directory ignores
itself. **The trap is deployment**: a LOCAL disk is per-machine, so a fresh
server serves 404s until `kinetix:help-screenshots` runs there — point
`KINETIX_HELP_SCREENSHOT_DISK` at S3 (or any shared disk), or make the capture
part of the deploy. Commit the PNGs instead only for a small, rarely-changed
manual with no object storage; a translated manual multiplies the file count per
language, so binaries in git add up fast.

Captures are served `private` with an ETag (never `public` — they stream through
an authenticated route) and cached for `screenshots.cache_ttl` seconds (default
86400, `0` disables). Embeds render `loading="lazy"`.

## Frontend

```vue
<KinetixHelpCenter />                       <!-- index: grouped cards + search -->
<KinetixHelpCenter layout="list" hide-toggle />
<KinetixHelpCenter :article-href="(slug) => `/docs/${slug}`" />

<KinetixHelpArticle :slug="slug" />          <!-- body + TOC + prev/next + language -->
<KinetixHelpArticle :slug="slug" hide-language-switcher />
```

`useKinetixHelp()` is the data layer (`articles`, `article`, `results`,
`loadArticles`, `loadArticle(slug, locale?)`, `search`, `locale`) — it caches
per language client-side and re-fetches on a language switch;
`clearKinetixHelpCache()` drops those caches. Heading anchors are
Unicode-aware, so translated headings stay addressable.

Scaffold the pages with `php artisan kinetix:make-help-page` (prints the routes
to add, team-aware).

## Files

- `src/Help/HelpManager.php` · `HelpArticle.php` · `HelpController.php` · `HelpSpotlightSource.php`
- `src/Commands/MakeHelpPageCommand.php` · `HelpScreenshotsCommand.php` · `HelpStatusCommand.php`
- `resources/js/components/KinetixHelpCenter.vue` · `KinetixHelpArticle.vue`
- `resources/js/composables/useKinetixHelp.ts` · `useKinetixHelpToc.ts`
