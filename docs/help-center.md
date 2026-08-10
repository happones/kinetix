# Help Center

An in-app user manual rendered from **markdown files your app owns**: articles
become searchable, permission-aware help pages with auto-generated screenshots.
The pipeline was designed for a "document every module" workflow — write one
`.md` per module, capture the screenshots with one artisan command, and users
only ever see the articles (and article *sections*) their permissions allow.

> **Required dependency (host app): Playwright.** Screenshot capture drives a
> real Chromium against your running app. Install it once in the host project:
>
> ```bash
> npm i -D playwright
> npx playwright install chromium
> ```
>
> The Help Center pages themselves work without Playwright — it is only needed
> to (re)generate screenshots.

---

## 1. Setup

Enable the module and scaffold the pages:

```bash
# .env
KINETIX_HELP_ENABLED=true
```

```bash
php artisan kinetix:make-help-page
```

The scaffold writes `resources/js/pages/Kinetix/Help/{Index,Show}.vue` (thin
mounts of `<KinetixHelpCenter>` / `<KinetixHelpArticle>`), seeds a sample
article in `resources/help/` when the directory is empty, and prints the routes
to register:

```php
Route::get('help', fn () => inertia('Kinetix/Help/Index'))->name('help.index');
Route::get('help/{article}', fn (...$params) => inertia('Kinetix/Help/Show', ['slug' => end($params)]))->name('help.show');

// Teams on? Nest both under the {current_team} segment like your other routes.
// The Show closure receives {current_team} first — end($params) is the article.
```

Data flows through Kinetix's own team-aware endpoints
(`{prefix}/help`, `/help/article/{slug}`, `/help/search`,
`/help/screenshots/{file}`) — the pages carry no controller code.

## 2. Writing articles

One markdown file per article in `resources/help/` (configurable via
`kinetix.help.path`). The slug is the filename minus `.md`; an `NN-` prefix
orders naturally.

```md
---
title: Products
group: Catalog
icon: package
order: 6
permission: products.view
---

# Products

Create, edit and organize your products.

![Products](screenshots/products.png)

## Creating a product

…
```

Front matter is **flat `key: value` pairs only** (no nested YAML — no extra
dependency needed). All keys are optional:

| Key | Effect |
|---|---|
| `title` | Card/list title. Falls back to the first `# ` heading, then the slug. |
| `group` | Groups articles into sections on the index. |
| `icon` | Lucide icon name for the card. |
| `order` | Explicit ordering (wins over the filename sort). |
| `permission` | Hides the article server-side from users the Gate denies. |

Files named `README.md` or starting with `_` are ignored (drafts).

### Translations

Add a locale variant next to the base file: `06-products.es.md`,
`06-products.pt.md`. The base `.md` must exist — variants alone are not
discovered.

**Resolution.** A request is served in the active language, falling back one
step at a time: `{slug}.pt_BR.md` → `{slug}.pt.md` →
`{slug}.{help.fallback_locale}.md` → the base `{slug}.md`. Titles, excerpts,
groups, ordering and search all read the resolved file, so an index is never a
mix of half-translated metadata.

**The language is part of the request, not an ambient assumption.** Every help
endpoint takes `?locale=` (validated against the locales the manual is
authored in — anything else is ignored), and the components send the app's
active language with every call. Three things follow:

- Switching language in your app's selector **re-fetches the index and the open
  article immediately** — no page reload, no article left in the previous
  language.
- Responses carry `Content-Language` + `Vary`, and the URL differs per
  language, so no browser or CDN cache can hand one language's payload to
  another.
- A reader can open a single article in another language without changing the
  whole app's locale (the chips described in §4).

<Screenshot name="help-article-language" alt="Help article — untranslated notice and per-article language chips" />

**Untranslated articles are marked, never silently swapped.** Each payload says
which language it is actually in (`locale`) and whether that differs from the
one asked for (`isFallback`). The components use it to mark the entry with its
real language — both visually and as a `lang` attribute, so screen readers
switch pronunciation instead of reading English as if it were Spanish. Prefer
hiding those articles entirely? Set `help.hide_untranslated` and they disappear
from the index, search and their own URL (404) until translated.

**Coverage and scaffolding.**

```bash
php artisan kinetix:help-status                    # matrix: article × locale
php artisan kinetix:help-status --strict           # non-zero exit on any gap (CI gate)

php artisan kinetix:make-help-page --locale=es --from=06-products
php artisan kinetix:make-help-page --locale=es     # every article missing that locale
```

The scaffolder copies the front matter verbatim (`permission`, `order` and
`icon` must not drift between languages) and reproduces the heading skeleton
with `TODO (es)` markers — structure to translate, never untranslated prose
masquerading as a translation.

### Permission-gated content

Two levels, both enforced **server-side**:

- **Whole article** — `permission: products.view` in the front matter: the
  article disappears from the index and search, and its URL 404s (existence is
  never leaked).
- **A block inside an article** — wrap it in gate comments:

```md
<!-- kinetix:can billing.manage -->
## Billing settings

Only users who may manage billing read this section.
<!-- /kinetix:can -->
```

Denied blocks are stripped from the markdown before rendering. Gates cannot be
nested; an unclosed gate strips to the end of the document. Abilities are your
host app's own Gate abilities (Spatie permissions, policies, `Gate::define` —
anything `Gate::allows()` resolves).

> **Security note.** Articles render with `html_input=strip` and
> `allow_unsafe_links=false` — raw HTML and `javascript:` links in markdown are
> neutralized.

## 3. Screenshots

Declare the pages to capture and run one command:

```php
// config/kinetix.php → 'help' => ['screenshots' => [...]]
'pages' => [
    'dashboard' => '/{team}/dashboard',
    'products'  => '/{team}/products',
    // Per-page overrides:
    'billing'   => ['path' => '/{team}/billing', 'full_page' => false, 'delay' => 1200],
],
```

```bash
php artisan vendor:publish --tag=kinetix-help-screenshots   # once
KINETIX_SCREENSHOT_EMAIL=demo@example.com \
KINETIX_SCREENSHOT_PASSWORD=password \
php artisan kinetix:help-screenshots
```

The command writes a manifest, drives the published Playwright runner
(`scripts/kinetix-help-screenshots.mjs` — logs in via the configurable
selectors, replaces `{team}` with the first URL segment after login), and
uploads the PNGs to the configured disk. Reference them from markdown as
`![Alt](screenshots/name.png)` — the renderer rewrites them to the streaming
endpoint.

### Storage driver

```php
'screenshots' => [
    'disk'        => env('KINETIX_HELP_SCREENSHOT_DISK'), // null = kinetix.filesystem.disk
    'path_prefix' => 'help/screenshots',
],
```

Local, `public`, S3 — anything works, including **private disks**: screenshots
always stream through the authenticated `kinetix.help.screenshot` route, never
a public URL. There is also a zero-setup fallback: PNGs committed to
`{help.path}/screenshots/` are served directly (the original
"commit the screenshots to the repo" workflow).

### Screenshots per language

A manual for a translated app needs translated captures. Run the command once
per language:

```bash
php artisan kinetix:help-screenshots --locale=es
```

Captures land in `{path_prefix}/{locale}/` and are served to articles written
in that language; anything without a localized capture falls back to the shared
set, so you can translate screenshots gradually. Markdown never changes — the
same `![Alt](screenshots/name.png)` embed resolves per language (the renderer
tags each embed with the article's own locale). The committed-PNG workflow
follows the same rule with `{help.path}/screenshots/{locale}/name.png`.

> Screenshots are auth-protected but not per-article gated (they are often
> shared across articles). Don't screenshot anything more sensitive than the
> articles themselves.

### Capture options & troubleshooting

| Config | Default | Notes |
|---|---|---|
| `selectors.email/password/submit` | `#email` / `#password` / `button[type=submit]` | Match your login form. |
| `selectors.logged_in_url` | `**/dashboard` | Post-login URL pattern. |
| `viewport` | 1440×900 | |
| `delay` | 700 ms | Settle time after `load`. The runner intentionally avoids `networkidle` — apps holding websockets (Echo/Reverb presence) never settle. |
| `base_url` | `app.url` | |
| `node_binary` | `node` | For non-PATH installs (also useful on Windows). |

Use a **dedicated screenshot user** without 2FA — the login flow is scripted.
`--only=dashboard,products` limits a run; `--keep-local` keeps the temp PNGs.
If PHP can't exec node, the command prints the manual invocation.

## 4. The components

```vue
<KinetixHelpCenter />                      <!-- index: grouped cards + search -->
<KinetixHelpCenter layout="list" />        <!-- start in list view -->
<KinetixHelpCenter hide-toggle />          <!-- lock the layout -->
<KinetixHelpCenter :article-href="(slug) => `/docs/${slug}`" />

<KinetixHelpArticle :slug="slug" />        <!-- article + TOC + prev/next -->
<KinetixHelpArticle :slug="slug" hide-language-switcher />
```

- **Search** is server-side (250 ms debounce) over the localized titles *and
  bodies* of the articles the user may see — served from an in-memory index,
  so a query costs no file reads.
- **Language** follows the app and reacts to the switcher. Articles available
  in more than one language show chips ("Read in: English · Español") that swap
  just that article; untranslated ones show a notice naming the language you're
  actually reading. Pass `hide-language-switcher` to keep the notice without
  the chips.
- Heading anchors are Unicode-aware: `## Configuración` becomes
  `#configuracion` and non-Latin headings keep their own script instead of
  collapsing into identical ids.

<Screenshot name="help-center" alt="Help Center index — grouped cards, search, and an untranslated article marked with its language" />
- The index groups by the `group` front matter key; cards/list both link via
  `article-href` (default: `{current path}/{slug}` — matching the scaffolded
  routes, teams included).
- The article view builds an "on this page" TOC from `h2`/`h3` headings with
  scroll tracking, renders prev/next from the same permission-filtered order as
  the index, and routes internal markdown links through Inertia.

### Spotlight

With `kinetix.spotlight.enabled`, help articles automatically appear in the
global command palette (permission-filtered). Result links resolve through the
`help.show` named route (configurable via `kinetix.help.show_route`).

## 5. Config reference

```php
'help' => [
    'enabled'    => env('KINETIX_HELP_ENABLED', false),
    'path'       => env('KINETIX_HELP_PATH'),        // null = resource_path('help')
    'show_route' => 'help.show',                     // Spotlight link target

    // Languages the manual may be served in. Null = infer: the Locale
    // module's locales, else the variants found on disk. A request can only
    // ask for a locale on this list.
    'locales' => null,

    // Language the base `{slug}.md` files are written in, and the last resort
    // before serving that base file. Null = config('app.fallback_locale').
    'fallback_locale' => env('KINETIX_HELP_FALLBACK_LOCALE'),

    // Hide articles with no variant in the active language instead of serving
    // them in the fallback one.
    'hide_untranslated' => env('KINETIX_HELP_HIDE_UNTRANSLATED', false),

    'cache' => [
        'enabled'  => env('KINETIX_HELP_CACHE', false),      // index only; HTML is
        'ttl'      => env('KINETIX_HELP_CACHE_TTL', 3600),   // never cached (per-user gating)
        'strategy' => env('KINETIX_HELP_CACHE_STRATEGY', 'fingerprint'),
    ],
    'screenshots' => [ /* see §3 */ ],
],
```

**Cache strategy.** `fingerprint` (default) keys the per-language index on the
article files' mtimes, so an edit is visible immediately — right for authoring
and staging. `ttl` skips that per-request stat entirely and lets entries expire
on the TTL — right for production, where articles ship with a deploy. Either
way the index holds metadata **and** the plain-text search corpus, built with
one read per article; the rendered HTML is never cached because it is
permission-gated per user.
