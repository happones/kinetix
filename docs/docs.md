# Docs

Technical documentation for your app — guides, an API reference, a CLI manual —
written as markdown files in your repository and served as pages: sections,
grouped navigation, an "on this page" rail, search, previous/next links,
translations with a marked fallback, and values filled per reader.

The pages use [Kinetix markdown](/markdown): callouts, highlighted code with
copy buttons, tabbed code groups and scrollable tables all work.

::: tip Help Center or Docs?
The [Help Center](/help-center) is an in-app manual for signed-in users, with
articles gated by permission. Docs are pages anyone can read (or only signed-in
users, if you say so), organized in sections, at their own URLs — the kind of
documentation a developer opens in a new tab.
:::

---

## 1. Setup

```bash
php artisan kinetix:make-docs-page
```

It writes `resources/js/pages/Kinetix/Docs.vue` — a thin page that mounts
`<KinetixDocs>` — and a sample section in `resources/docs/` when the directory
is empty. Then turn the module on:

```php
// config/kinetix.php
'docs' => [
    'enabled' => true,
    // …
],
```

Kinetix registers the routes itself:

| Route | Name | |
|---|---|---|
| `GET /docs` | `docs.index` | opens the first section |
| `GET /docs/{section}` | `docs.section` | the section's landing page, or its first page |
| `GET /docs/{section}/{page}` | `docs.show` | a page |
| `GET /docs/search.json` | `docs.search` | the search index |

They're public (`['web']` middleware) and not team-scoped: the docs are the same
for every reader. Wrap the page in a layout that fits — a public one, or your
app layout if you make the docs private:

```php
'routes' => [
    'prefix'     => 'docs',
    'name'       => 'docs.',
    'middleware' => ['web', 'auth'],   // signed-in readers only
    'domain'     => null,              // e.g. 'docs.example.com'
],
```

## 2. Writing pages

```
resources/docs/
├── en/
│   ├── guide/
│   │   ├── index.md              → /docs/guide
│   │   ├── 01-install.md         → /docs/guide/install
│   │   └── 02-configure.md       → /docs/guide/configure
│   └── api/
│       └── authentication.md     → /docs/api/authentication
└── es/
    └── guide/
        └── 01-install.md         (the Spanish install page)
```

- **Sections** are the directories under each language. Their order comes from
  `docs.sections` when you set it, else from each section's `index.md`.
- **A section's `index.md`** is its landing page and names the section:

  ```md
  ---
  section: Guide
  title: Overview
  order: 1
  description: What this guide covers.
  ---
  ```

  `section` is the name in the header (falls back to `title`), `order` places
  the section among the others. Without an `index.md`, the section is named
  after its directory and `/docs/{section}` opens its first page.
- **Pages** are ordered by `order`, then file name — an `NN-` prefix orders
  them without showing in the URL — and grouped in the sidebar by `group`:

  ```md
  ---
  title: Install
  group: Basics
  description: Get the CLI running.
  ---
  ```

  Without a `title`, the first `# ` heading is used, then the file name.
  `description` shows under the title and as the page's meta description.
- Files named `README.md` or starting with `_` are skipped (drafts).

### Links between pages

Link to the markdown file, the way it works on a code host:

```md
See [configuration](02-configure.md#environment) and the
[authentication reference](../api/authentication.md).
```

Kinetix turns those into page URLs (`/docs/guide/configure#environment`).

### Placeholders

<code v-pre>{{ name }}</code> is filled in per request, in text and in code blocks alike:

```bash
curl {{ app_url }}/api/ping -H "Authorization: Bearer {{ token_prefix }}…"
```

`app_name`, `app_url` and `docs_url` are always there. Add your own with a
callable array that receives the request:

```php
// config/kinetix.php
'docs' => [
    'placeholders' => [App\Docs\Placeholders::class, 'resolve'],
],
```

```php
namespace App\Docs;

use Illuminate\Http\Request;

class Placeholders
{
    /**
     * @return array<string, string>
     */
    public static function resolve(Request $request): array
    {
        return [
            'team_slug' => $request->user()?->currentTeam?->slug ?? 'your-team',
            'api_url'   => url('/api/v1'),
        ];
    }
}
```

Values are escaped. A placeholder nobody fills stays as written.

## 3. Translations

Write every page in the fallback language (`docs.fallback_locale`, else
`app.fallback_locale`) and translate them one by one into `{locale}/` — the
same path. A reader gets the page in the app's current language when it
exists, else in the fallback one, **marked**: a notice says which language
they're reading, and the body carries its real `lang`. A page served in the
fallback language joins the translated name of its group.

The language follows the app (`app()->getLocale()`), so a language switcher
that sets it switches the docs too. `pt_BR` falls back to `pt`, then to the
fallback language.

## 4. Search

The search box (also on `/`) loads `/docs/search.json` once — every page's
title, headings and text in the reader's language — and searches in the
browser: every word must appear, accents and case don't matter, a word in a
title counts most, and a matching heading is where the result leads. Arrow
keys and Enter pick a result.

## 5. Checking the docs

```bash
php artisan kinetix:docs-check            # broken links fail it
php artisan kinetix:docs-check --strict   # untranslated pages fail it too
```

It renders every page in every language and reports links to pages or
headings that don't exist, and the pages each language is missing. Run it in
CI.

## 6. API reference from OpenAPI

Point a section at an OpenAPI 3 document and Kinetix writes its pages: a
landing page (the API's description, base URL, authentication and resources)
and one page per tag, with every operation's parameters, request body,
responses and a ready-to-run request in curl, PHP and JavaScript.

```php
'docs' => [
    'openapi' => [
        'reference' => 'openapi.json',   // relative to the project root
    ],
],
```

Any generator's output works. With [Scramble](https://scramble.dedoc.co):

```bash
php artisan scramble:export --path=openapi.json
```

YAML documents need `symfony/yaml`. The generated pages behave like written
ones — table of contents, search, highlighted examples, `docs-check` — and
you can mix both:

- an `index.md` in `{locale}/reference/` replaces the generated landing page;
- a written `{locale}/reference/{tag}.md` replaces that tag's page (the slug is
  the tag name, slugged: `Order items` → `order-items`);
- other written pages in the section stay alongside the generated ones.

A schema's nested objects are spelled out (`dimensions.width`, `items[].sku`,
three levels deep); a schema that contains itself is named, not opened again.
Examples come from the document's `example`/`examples`, else from each
field's type.

## 7. The page

`DocsController` renders `docs.view` (default `Kinetix/Docs`) with one `docs`
prop (`KinetixDocsPayload` in `@/types/kinetix`):

```vue
<script setup lang="ts">
import KinetixDocs from '@/components/kinetix/KinetixDocs.vue';
import type { KinetixDocsPayload } from '@/types/kinetix';

defineProps<{ docs: KinetixDocsPayload }>();
</script>

<template>
  <KinetixDocs :docs="docs" />
</template>
```

`<KinetixDocs>` sets the document title and meta description. For crawlers
without JavaScript, the same values reach your root Blade view as
`$kinetixDocs` (`title`, `description`, `url`):

```blade
@isset($kinetixDocs)
  <meta name="description" content="{{ $kinetixDocs['description'] }}">
  <link rel="canonical" href="{{ $kinetixDocs['url'] }}">
@endisset
```

"Edit this page" appears when you set `docs.edit_url`:

```php
'edit_url' => 'https://github.com/acme/app/edit/main/resources/docs/{path}',
```

## Configuration

```php
'docs' => [
    'enabled'         => false,
    'path'            => null,          // resource_path('docs')
    'sections'        => null,          // ['guide', 'api'] to fix their order
    'locales'         => null,          // the language directories
    'fallback_locale' => null,          // app.fallback_locale
    'routes'          => [
        'enabled'    => true,
        'prefix'     => 'docs',
        'name'       => 'docs.',
        'middleware' => ['web'],
        'domain'     => null,
    ],
    'view'         => 'Kinetix/Docs',
    'placeholders' => null,             // [Class::class, 'method']
    'openapi'      => [],               // ['reference' => 'openapi.json']
    'edit_url'     => null,
    'cache'        => [
        'enabled' => true,              // rendered pages, keyed by path + mtime
        'store'   => null,              // the default store
    ],
],
```

Rendered pages are cached under their path and modification time, so an edit
shows up on the next request; the index of sections and pages is rebuilt once
per request.
