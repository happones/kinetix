# Markdown Content

The markdown your app ships — help articles today, docs pages next — renders
through one engine: GitHub-flavored markdown, safe to put in a page, plus
callouts, highlighted code, tabbed code groups, heading anchors and a table of
contents. `<KinetixMarkdownContent>` displays the result.

The Help Center uses it already: everything below works in its articles.

---

## Writing

### Callouts

GitHub's alert syntax. Text after the marker replaces the title.

````md
> [!NOTE]
> Exports run in the background; you get a notification when one is ready.

> [!TIP] Faster imports
> Upload a CSV instead of pasting rows.

> [!WARNING]
> Rotating a token revokes the old one at once.
````

Five types: `NOTE`, `TIP`, `IMPORTANT`, `WARNING`, `CAUTION`. Their titles are
translated (`kinetix.markdown_note`, …). A blockquote without a marker stays a
blockquote.

### Code

A fenced block gets a label (its language, or a `[Title]`) and a copy button:

````md
```php [config/services.php]
'acme' => ['token' => env('ACME_TOKEN')],
```
````

Two or more **titled** blocks in a row become one block with tabs:

````md
```bash [npm]
npm install @acme/cli
```

```bash [yarn]
yarn add @acme/cli
```
````

The tabs are a real tab list: arrow keys, Home and End move between them, and
the copy button copies the tab on show. A single titled block stays a single
block.

### Headings and tables

Every heading gets an id (`## Configuración` → `#configuracion`; letters of any
script are kept, accents are folded on Latin text only), and h2/h3 make the
table of contents. Wide tables scroll inside their own box instead of widening
the page.

### Images

Images open in the [lightbox](/lightbox) as one gallery per document, zooming
out of the image clicked, with a caption from the `<figcaption>`, the title or
the alt text. They are keyboard-reachable buttons named after their alt text, so
write alt text that describes the picture. Images inside a link keep the link.
On by default: `kinetix.lightbox.markdown = false` turns it off app-wide, and
the `lightbox` prop overrides the config either way.

### What is stripped

Raw HTML in the source is removed, `javascript:` and `data:` links are dropped,
and an inline `<script>` loses its contents too — content can format itself
but never run script.

---

## Highlighting

Code is highlighted on the server by [Phiki](https://github.com/phikiphp/phiki)
(TextMate grammars, the same ones editors use), so pages ship colored HTML and
no highlighter runs in the browser. It's optional:

```bash
composer require phiki/phiki
```

Without it, code blocks render as plain, escaped code — still labelled, still
copyable. A language Phiki doesn't know renders the same way.

```php
// config/kinetix.php
'markdown' => [
    'highlight' => [
        'enabled'     => true,
        // Rendered together; the dark one applies under `.dark`.
        'themes'      => ['light' => 'github-light', 'dark' => 'github-dark'],
        // A cache store for highlighted blocks (null = none).
        'cache_store' => null,
    ],
],
```

To use another engine, bind your own
`Happones\Kinetix\Markdown\Highlighting\CodeHighlighter`: `highlight($code,
$language)` returns a `<pre>` element, or `null` for plain code.

---

## Rendering your own content

```php
use Happones\Kinetix\Markdown\FrontMatter;
use Happones\Kinetix\Markdown\MarkdownRenderer;

$source = file_get_contents(resource_path('guides/setup.md'));
$meta   = FrontMatter::parse($source);  // ['title' => '…', …]

$page = app(MarkdownRenderer::class)->render(
    FrontMatter::strip($source),
    locale: 'es',      // translates the labels; default: the app's
    anchors: true,     // a # permalink on every h2–h4
);

$page->html;  // safe HTML
$page->toc;   // [['level' => 2, 'id' => 'install', 'text' => 'Install'], …]
$page->text;  // one line of plain text, for search
```

Front matter is flat `key: value` pairs between `---` fences (no YAML
dependency).

```vue
<script setup lang="ts">
import KinetixMarkdownContent from '@/components/kinetix/KinetixMarkdownContent.vue';

defineProps<{ html: string }>();
</script>

<template>
  <KinetixMarkdownContent :html="html" as="article" />
</template>
```

`as` picks the root element (`div`, `article`, `section`); attributes and
listeners fall through to it, so `:lang`, `dir` or `@click` work as on any
element. The ref exposes the root as `el` — what a table-of-contents builder
needs.
