---
name: kinetix-docs
description: "Technical documentation from markdown files: sections, grouped navigation, on-this-page rail, search, translations with a marked fallback, per-request placeholders and a link checker. Activates when writing docs pages under resources/docs, enabling kinetix.docs, mounting KinetixDocs, or wiring docs placeholders / the docs check in CI."
license: MIT
metadata:
  author: happones
---

# Kinetix Docs Development

## When to Apply

Activate this skill when:
- Writing pages in `resources/docs/{locale}/{section}/{page}.md` (`kinetix.docs.path`).
- Turning on `kinetix.docs` or changing its routes, view, placeholders or cache.
- Mounting `<KinetixDocs>` or scaffolding the page (`kinetix:make-docs-page`).
- Checking the docs in CI (`kinetix:docs-check`).

## Documentation

Full details in `docs/docs.md` (https://happones.github.io/kinetix/docs). The
markdown syntax (callouts, code groups, highlighting) is in `docs/markdown.md`.

## Rules

- Docs are markdown files in the repo — never a database table.
- Routes are registered by Kinetix (`docs.index`, `docs.section`, `docs.show`,
  `docs.search`), public by default (`docs.routes.middleware = ['web']`), NOT
  team-scoped. Link with `route('docs.show', ['section' => 'guide', 'page' => 'install'])`.
- `index.md` = section landing page; front matter `section` (name), `order`
  (section order), `title`, `description`. Pages: `title`, `group`,
  `description`, `order`; an `NN-` file prefix orders without showing in the URL.
- Link pages by file (`[Setup](02-configure.md#env)`, `../api/auth.md`); they
  become page URLs. `README.md` and `_draft.md` are skipped.
- `{{ name }}` placeholders: `app_name`, `app_url`, `docs_url` built in; more via
  `docs.placeholders` as a CALLABLE ARRAY (`[Class::class, 'resolve']`, receives
  the Request) — never a closure (config:cache).
- Translations: same path under another locale directory; missing pages are
  served in `fallback_locale` and marked (`page.fallback`), never silently.
- API reference: `docs.openapi = ['reference' => 'openapi.json']` generates a
  landing page + one page per tag (params, body, responses, curl/PHP/JS
  samples) from an OpenAPI 3 document (Scramble: `php artisan scramble:export`).
  Written `index.md` / `{tag-slug}.md` in that section replace generated pages.
- Run `php artisan kinetix:docs-check` (`--strict` also fails untranslated pages;
  it also reports OpenAPI documents that can't be read).

## Page

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

The root Blade view also receives `$kinetixDocs` (`title`, `description`, `url`)
for meta tags without JavaScript.
