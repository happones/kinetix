<?php

declare(strict_types=1);

namespace Happones\Kinetix\Docs;

use Happones\Kinetix\Markdown\FrontMatter;
use Happones\Kinetix\Markdown\MarkdownRenderer;
use Happones\Kinetix\Markdown\RenderedMarkdown;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Reads the docs: markdown files in `{path}/{locale}/{section}/{page}.md`.
 *
 * - A page missing in the reader's language is served from the fallback
 *   locale, marked as such (never silently swapped).
 * - A section's `index.md` is its landing page; its front matter names the
 *   section (`section`, else `title`) and orders it among the others (`order`).
 * - Pages are ordered by `order`, then file name (an `NN-` prefix orders
 *   without showing in the URL), and grouped by `group`.
 * - `{{ name }}` placeholders are filled per request (see placeholders()), and
 *   links to other `.md` files become links to their pages.
 *
 * Bound per request (scoped): the index is built once per request and locale,
 * and each rendered page is cached under its own path and mtime, so an edit
 * shows up on the next request.
 */
class DocsManager
{
    /** Bump when the rendered output changes shape, to drop cached pages. */
    protected const RENDER_VERSION = 1;

    /** @var array<string, array<string, DocsSection>> */
    protected array $memo = [];

    protected ?string $fingerprint = null;

    /** @var array<string, string>|null this request's placeholder values */
    protected ?array $values = null;

    public function __construct(protected MarkdownRenderer $renderer) {}

    public function path(): string
    {
        $configured = config('kinetix.docs.path');

        return rtrim(is_string($configured) && $configured !== '' ? $configured : resource_path('docs'), '/');
    }

    public function fallbackLocale(): string
    {
        $configured = config('kinetix.docs.fallback_locale');

        return is_string($configured) && $configured !== '' ? $configured : (string) config('app.fallback_locale', 'en');
    }

    /**
     * The languages the docs are written in: `docs.locales`, else the locale
     * directories on disk.
     *
     * @return array<int, string>
     */
    public function locales(): array
    {
        $configured = config('kinetix.docs.locales');

        if (is_array($configured) && $configured !== []) {
            return array_values(array_map('strval', $configured));
        }

        $directories = array_map('basename', glob($this->path().'/*', GLOB_ONLYDIR) ?: []);

        return array_values(array_unique([$this->fallbackLocale(), ...$directories]));
    }

    /**
     * The docs language for a requested one: exact (`pt_BR`), then its base
     * language (`pt`), then the fallback.
     */
    public function locale(?string $requested = null): string
    {
        $requested = (string) ($requested ?? app()->getLocale());
        $locales   = $this->locales();

        foreach ([$requested, Str::before(str_replace('-', '_', $requested), '_')] as $candidate) {
            if (in_array($candidate, $locales, true)) {
                return $candidate;
            }
        }

        return $this->fallbackLocale();
    }

    /**
     * The sections in a language, ordered, each with its ordered pages.
     *
     * @return array<string, DocsSection>
     */
    public function sections(?string $locale = null): array
    {
        $locale = $this->locale($locale);
        $key    = $locale.'@'.$this->fingerprint();

        return $this->memo[$key] ??= $this->buildSections($locale);
    }

    public function section(string $key, ?string $locale = null): ?DocsSection
    {
        return $this->sections($locale)[$key] ?? null;
    }

    /**
     * A page by section and slug (empty slug: the section's landing page).
     */
    public function find(string $section, string $slug, ?string $locale = null): ?DocsPage
    {
        foreach ($this->section($section, $locale)->pages ?? [] as $page) {
            if ($page->slug === $slug) {
                return $page;
            }
        }

        return null;
    }

    /**
     * A page's HTML, table of contents and plain text, placeholders filled in
     * for this request.
     */
    public function render(DocsPage $page, ?Request $request = null): RenderedMarkdown
    {
        $rendered = $this->renderCached($page);
        $values   = $this->placeholders($request);

        return new RenderedMarkdown(
            $this->fill($rendered->html, $values, escape: true),
            array_map(fn (array $entry): array => [...$entry, 'text' => $this->fill($entry['text'], $values, escape: false)], $rendered->toc),
            $this->fill($rendered->text, $values, escape: false),
        );
    }

    /**
     * Every page of every section with its headings and text: what the docs
     * search reads.
     *
     * @return array<int, array{section: string, sectionTitle: string, slug: string, title: string, group: string, url: string, headings: array<int, array{id: string, text: string}>, text: string}>
     */
    public function searchIndex(?string $locale = null, ?Request $request = null): array
    {
        $index = [];

        foreach ($this->sections($locale) as $section) {
            foreach ($section->pages as $page) {
                $rendered = $this->render($page, $request);

                $index[] = [
                    'section'      => $section->key,
                    'sectionTitle' => $section->title,
                    'slug'         => $page->slug,
                    'title'        => $page->title,
                    'group'        => $page->group,
                    'url'          => $this->url($section->key, $page->slug),
                    'headings'     => array_map(static fn (array $entry): array => ['id' => $entry['id'], 'text' => $entry['text']], $rendered->toc),
                    'text'         => $rendered->text,
                ];
            }
        }

        return $index;
    }

    /**
     * A page's URL (path only, so it holds behind any host): the section's
     * landing page for an empty slug.
     */
    public function url(string $section, string $slug = ''): string
    {
        $name = (string) config('kinetix.docs.routes.name', 'docs.');

        return $slug === ''
            ? route($name.'section', ['section' => $section], false)
            : route($name.'show', ['section' => $section, 'page' => $slug], false);
    }

    /**
     * Values for `{{ name }}`: `app_name`, `app_url` and `docs_url`, plus
     * whatever `docs.placeholders` (a callable array receiving the request)
     * returns — a team's slug, an API base URL, the reader's token prefix…
     *
     * @return array<string, string>
     */
    public function placeholders(?Request $request = null): array
    {
        $values = [
            'app_name' => (string) config('app.name'),
            'app_url'  => rtrim((string) config('app.url'), '/'),
            'docs_url' => rtrim((string) config('app.url'), '/').'/'.trim((string) config('kinetix.docs.routes.prefix', 'docs'), '/'),
        ];

        $resolver = config('kinetix.docs.placeholders');

        if (is_callable($resolver)) {
            foreach ((array) $resolver($request ?? request()) as $name => $value) {
                $values[(string) $name] = (string) $value;
            }
        }

        return $values;
    }

    /**
     * @return array<string, DocsSection>
     */
    protected function buildSections(string $locale): array
    {
        $fallback   = $this->fallbackLocale();
        $configured = config('kinetix.docs.sections');
        $keys       = is_array($configured) && $configured !== []
            ? array_values(array_map('strval', $configured))
            : $this->discoverSections([$fallback, $locale]);
        $sections = [];

        foreach ($keys as $key) {
            $pages = $this->buildPages($key, $locale, $fallback);

            if ($pages === []) {
                continue;
            }

            $landing = $pages[0]->isLanding() ? $pages[0] : null;
            $meta    = $landing !== null ? FrontMatter::parse((string) file_get_contents($landing->path)) : [];

            $sections[$key] = new DocsSection(
                key: $key,
                title: $this->fillMeta($meta['section'] ?? $meta['title'] ?? Str::headline($key)),
                description: $this->fillMeta($meta['description'] ?? ''),
                order: isset($meta['order']) ? (int) $meta['order'] : PHP_INT_MAX,
                pages: $pages,
            );
        }

        // Configured sections keep their configured order.
        if (! (is_array($configured) && $configured !== [])) {
            uasort($sections, static fn (DocsSection $a, DocsSection $b): int => [$a->order, $a->key] <=> [$b->order, $b->key]);
        }

        return $sections;
    }

    /**
     * @param  array<int, string> $locales
     * @return array<int, string>
     */
    protected function discoverSections(array $locales): array
    {
        $keys = [];

        foreach (array_unique($locales) as $locale) {
            foreach (glob($this->path()."/{$locale}/*", GLOB_ONLYDIR) ?: [] as $directory) {
                $keys[] = basename($directory);
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * @return array<int, DocsPage>
     */
    protected function buildPages(string $section, string $locale, string $fallback): array
    {
        $files = [];

        // The reader's language overrides the fallback file by file.
        foreach (array_unique([$fallback, $locale]) as $candidate) {
            foreach (glob($this->path()."/{$candidate}/{$section}/*.md") ?: [] as $path) {
                $name = basename($path, '.md');

                if ($name === 'README' || str_starts_with($name, '_')) {
                    continue;
                }

                $files[$name] = ['path' => $path, 'locale' => $candidate];
            }
        }

        $groups = $this->translatedGroups($section, $files, $locale, $fallback);
        $pages  = [];

        foreach ($files as $name => $file) {
            $markdown = (string) file_get_contents($file['path']);
            $meta     = FrontMatter::parse($markdown);
            $slug     = $name === 'index' ? '' : (string) preg_replace('/^\d+[-_]/', '', $name);
            $group    = $meta['group'] ?? '';

            $pages[] = new DocsPage(
                section: $section,
                slug: $slug,
                title: $this->fillMeta($meta['title'] ?? $this->firstHeading($markdown) ?? Str::headline($slug === '' ? $section : $slug)),
                description: $this->fillMeta($meta['description'] ?? ''),
                group: $file['locale'] !== $locale ? ($groups[$group] ?? $group) : $group,
                order: $slug === '' ? PHP_INT_MIN : (isset($meta['order']) ? (int) $meta['order'] : PHP_INT_MAX),
                path: $file['path'],
                locale: $file['locale'],
                fallback: $file['locale'] !== $locale,
            );
        }

        usort($pages, static fn (DocsPage $a, DocsPage $b): int => [$a->order, basename($a->path)] <=> [$b->order, basename($b->path)]);

        return $pages;
    }

    /**
     * A page served in the fallback language joins the translated name of its
     * group, learned from a translated page that shares it.
     *
     * @param  array<string, array{path: string, locale: string}> $files
     * @return array<string, string>
     */
    protected function translatedGroups(string $section, array $files, string $locale, string $fallback): array
    {
        $groups = [];

        foreach ($files as $name => $file) {
            $original = $this->path()."/{$fallback}/{$section}/{$name}.md";

            if ($file['locale'] !== $locale || $locale === $fallback || ! is_file($original)) {
                continue;
            }

            $from = FrontMatter::parse((string) file_get_contents($original))['group']     ?? null;
            $to   = FrontMatter::parse((string) file_get_contents($file['path']))['group'] ?? null;

            if ($from !== null && $to !== null) {
                $groups[$from] ??= $to;
            }
        }

        return $groups;
    }

    protected function renderCached(DocsPage $page): RenderedMarkdown
    {
        $render = function () use ($page): array {
            $markdown = FrontMatter::strip((string) file_get_contents($page->path));
            // Placeholders travel through rendering as one word, so a
            // highlighter can't split them; they're filled per request.
            $markdown = (string) preg_replace('/\{\{\s*([A-Za-z][A-Za-z0-9_]*)\s*\}\}/', 'kxph__$1__kxph', $markdown);
            $rendered = $this->renderer->render($markdown, $page->locale, anchors: true);

            return [...$rendered->toArray(), 'html' => $this->resolveLinks($rendered->html, $page)];
        };

        if (! config('kinetix.docs.cache.enabled', true)) {
            return RenderedMarkdown::fromArray($render());
        }

        $key = sprintf('kinetix.docs.page:%d:%s:%s:%d', static::RENDER_VERSION, md5($page->path), $page->locale, (int) $page->updatedAt());

        /** @var array{html: string, toc: array<int, array{level: int, id: string, text: string}>, text: string} $data */
        $data = $this->cache()->rememberForever($key, $render);

        return RenderedMarkdown::fromArray($data);
    }

    /**
     * `[Install](install.md)`, `(../api/02-auth.md#tokens)`: links between the
     * markdown files (the way they work on a code host) become page URLs.
     */
    protected function resolveLinks(string $html, DocsPage $page): string
    {
        return (string) preg_replace_callback(
            '/href="(?![a-z][a-z0-9+.-]*:|\/|#)([^"#?]+?)\.md(#[^"]*)?"/i',
            function (array $matches) use ($page): string {
                $segments = [$page->section];

                foreach (explode('/', $matches[1]) as $segment) {
                    match ($segment) {
                        '', '.' => null,
                        '..'    => array_pop($segments),
                        default => $segments[] = $segment,
                    };
                }

                $name    = (string) array_pop($segments);
                $section = (string) (end($segments) ?: $page->section);
                $slug    = $name === 'index' ? '' : (string) preg_replace('/^\d+[-_]/', '', $name);

                return 'href="'.e($this->url($section, $slug)).($matches[2] ?? '').'"';
            },
            $html,
        );
    }

    /**
     * `{{ name }}` in front matter (a title, a description), filled for this
     * request — the manager lives for one request. Raw: whoever prints it
     * escapes it.
     */
    protected function fillMeta(string $text): string
    {
        if (! str_contains($text, '{{')) {
            return $text;
        }

        $this->values ??= $this->placeholders();

        return $this->fill((string) preg_replace('/\{\{\s*([A-Za-z][A-Za-z0-9_]*)\s*\}\}/', 'kxph__$1__kxph', $text), $this->values, escape: false);
    }

    /**
     * @param array<string, string> $values
     */
    protected function fill(string $content, array $values, bool $escape): string
    {
        return (string) preg_replace_callback(
            '/kxph__([A-Za-z0-9_]+?)__kxph/',
            static function (array $matches) use ($values, $escape): string {
                if (! array_key_exists($matches[1], $values)) {
                    return '{{ '.$matches[1].' }}';
                }

                return $escape ? e($values[$matches[1]]) : $values[$matches[1]];
            },
            $content,
        );
    }

    protected function firstHeading(string $markdown): ?string
    {
        return preg_match('/^#\s+(.+)$/m', FrontMatter::strip($markdown), $matches) === 1 ? trim($matches[1]) : null;
    }

    /**
     * Every markdown file's path and mtime, hashed: the index's memo key.
     */
    protected function fingerprint(): string
    {
        if ($this->fingerprint !== null) {
            return $this->fingerprint;
        }

        $parts = [];

        if (is_dir($this->path())) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->path(), RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file->getExtension() === 'md') {
                    $parts[] = $file->getPathname().':'.$file->getMTime();
                }
            }
        }

        sort($parts);

        return $this->fingerprint = md5(implode('|', $parts));
    }

    protected function cache(): Repository
    {
        $store = config('kinetix.docs.cache.store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }
}
