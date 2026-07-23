<?php

declare(strict_types=1);

namespace Happones\Kinetix\Help;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;

/**
 * The Help Center engine: discovers markdown articles in the host-owned
 * directory (`kinetix.help.path`, default `resources/help`), renders them to
 * safe HTML, and enforces permission gating.
 *
 * Authoring model (ported from the docs/manual pattern):
 *  - slug = filename minus `.md` (an `NN-` prefix orders naturally);
 *  - locale variants live next to the base file as `{slug}.{locale}.md`
 *    (regional locales fall back `pt_BR` → `pt` → base);
 *  - optional flat front matter: title / permission / icon / order / group;
 *  - screenshots referenced as `![Alt](screenshots/name.png)` are rewritten to
 *    the streaming endpoint at render time;
 *  - `<!-- kinetix:can ability -->…<!-- /kinetix:can -->` blocks are stripped
 *    for users the Gate denies (processed on the RAW markdown, before
 *    rendering — the safe renderer drops HTML comments).
 *
 * Discovery metadata may be cached (`kinetix.help.cache`); rendered HTML is
 * NEVER cached because its content is per-user (permission gating).
 */
class HelpManager
{
    /**
     * The articles directory.
     */
    public function path(): string
    {
        $configured = config('kinetix.help.path');

        return is_string($configured) && $configured !== ''
            ? $configured
            : resource_path('help');
    }

    /**
     * The authorized articles for a user, ordered (front matter `order`, then
     * slug). Pass null to resolve for a guest (only ungated articles).
     *
     * @return array<int, HelpArticle>
     */
    public function articles(?Authenticatable $user = null): array
    {
        return array_values(array_filter(
            $this->discover(),
            fn (HelpArticle $article): bool => $this->allows($user, $article->permission),
        ));
    }

    /**
     * Find an authorized article by slug. Returns null both for unknown slugs
     * and for articles the user may not see (a 404, never a 403 — gated
     * articles must not leak their existence).
     */
    public function find(string $slug, ?Authenticatable $user = null): ?HelpArticle
    {
        $slug = basename($slug);

        foreach ($this->articles($user) as $article) {
            if ($article->slug === $slug) {
                return $article;
            }
        }

        return null;
    }

    /**
     * Render an article's localized markdown to safe HTML for the user:
     * front matter stripped, denied `kinetix:can` blocks removed (pre-render),
     * HTML input stripped + unsafe links denied, screenshot sources rewritten
     * to the streaming endpoint.
     */
    public function render(HelpArticle $article, ?Authenticatable $user = null): string
    {
        $markdown = (string) file_get_contents($this->localizedPath($article->slug));
        $markdown = $this->stripFrontMatter($markdown);
        $markdown = $this->applyBlockGates($markdown, $user);

        $html = (string) Str::markdown($markdown, [
            'html_input'         => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return str_replace('src="screenshots/', 'src="'.$this->screenshotBaseUrl().'/', $html);
    }

    /**
     * Case-insensitive search over the authorized articles' localized titles
     * and bodies, with a plain-text excerpt around the first match.
     *
     * @return array<int, array{slug: string, title: string, group: ?string, excerpt: string}>
     */
    public function search(string $query, ?Authenticatable $user = null): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        $results = [];

        foreach ($this->articles($user) as $article) {
            $body = $this->plainText($this->stripFrontMatter(
                (string) file_get_contents($this->localizedPath($article->slug)),
            ));

            $inTitle = mb_stripos($article->title, $query) !== false;
            $atBody  = mb_stripos($body, $query);

            if (! $inTitle && $atBody === false) {
                continue;
            }

            $results[] = [
                'slug'    => $article->slug,
                'title'   => $article->title,
                'group'   => $article->group,
                'excerpt' => $atBody !== false
                    ? $this->excerptAround($body, (int) $atBody, mb_strlen($query))
                    : $article->excerpt,
            ];
        }

        return $results;
    }

    /**
     * Resolve the markdown file for the current locale: `{slug}.{locale}.md`,
     * then the language part of a regional locale (`pt_BR` → `pt`), then the
     * base `{slug}.md`.
     */
    public function localizedPath(string $slug): string
    {
        $slug   = basename($slug);
        $locale = (string) app()->getLocale();

        $candidates = [$locale];
        $language   = (string) preg_replace('/[_-].*$/', '', $locale);

        if ($language !== '' && $language !== $locale) {
            $candidates[] = $language;
        }

        foreach ($candidates as $candidate) {
            $path = $this->path()."/{$slug}.{$candidate}.md";

            if (is_file($path)) {
                return $path;
            }
        }

        return $this->path()."/{$slug}.md";
    }

    // -------------------------------------------------------------------
    // Discovery
    // -------------------------------------------------------------------

    /**
     * All articles regardless of user (permission field included), sorted.
     * Cached when `kinetix.help.cache.enabled` — keyed on the file set and
     * mtimes plus the locale, so edits invalidate automatically.
     *
     * @return array<int, HelpArticle>
     */
    protected function discover(): array
    {
        $files = $this->baseFiles();

        if ($files === []) {
            return [];
        }

        if (! config('kinetix.help.cache.enabled', false)) {
            return $this->buildArticles($files);
        }

        $fingerprint = md5(implode('|', array_map(
            static fn (string $path): string => $path.':'.(string) filemtime($path),
            $this->allMarkdownFiles(),
        )));
        $key = 'kinetix.help.articles.'.app()->getLocale().'.'.$fingerprint;

        return Cache::remember(
            $key,
            (int) config('kinetix.help.cache.ttl', 3600),
            fn (): array => $this->buildArticles($files),
        );
    }

    /**
     * The base (non-variant) article files: `*.md` minus README, `_*` drafts
     * and locale variants (`{slug}.{locale}.md`, regional-aware).
     *
     * @return array<int, string>
     */
    protected function baseFiles(): array
    {
        return array_values(array_filter(
            $this->allMarkdownFiles(),
            static function (string $path): bool {
                $name = basename($path);

                return $name !== 'README.md'
                    && ! str_starts_with($name, '_')
                    && ! preg_match('/\.[a-z]{2}([_-][A-Za-z]{2,4})?\.md$/', $name);
            },
        ));
    }

    /**
     * @return array<int, string>
     */
    protected function allMarkdownFiles(): array
    {
        return glob($this->path().'/*.md') ?: [];
    }

    /**
     * @param  array<int, string>      $files
     * @return array<int, HelpArticle>
     */
    protected function buildArticles(array $files): array
    {
        $articles = array_map(fn (string $path): HelpArticle => $this->buildArticle($path), $files);

        usort($articles, static function (HelpArticle $a, HelpArticle $b): int {
            return [$a->order, $a->slug] <=> [$b->order, $b->slug];
        });

        return $articles;
    }

    protected function buildArticle(string $path): HelpArticle
    {
        $slug     = basename($path, '.md');
        $markdown = (string) file_get_contents($this->localizedPath($slug));
        $meta     = $this->parseFrontMatter($markdown);
        $body     = $this->stripFrontMatter($markdown);

        $title = $meta['title'] ?? $this->firstHeading($body) ?? (string) str($slug)->after('-')->headline();

        return new HelpArticle(
            slug: $slug,
            title: $title,
            group: $meta['group'] ?? null,
            icon: $meta['icon']   ?? null,
            order: isset($meta['order']) ? (int) $meta['order'] : PHP_INT_MAX,
            permission: $meta['permission'] ?? null,
            excerpt: $this->firstParagraph($body),
        );
    }

    // -------------------------------------------------------------------
    // Front matter (flat `key: value` pairs between --- fences; no YAML dep)
    // -------------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    protected function parseFrontMatter(string $markdown): array
    {
        if (! preg_match('/^---\R(.*?)\R---\R?/s', $markdown, $matches)) {
            return [];
        }

        $meta = [];

        foreach (preg_split('/\R/', $matches[1]) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $key           = trim($key);
            $value         = trim(trim($value), '"\'');

            if ($key !== '' && $value !== '') {
                $meta[$key] = $value;
            }
        }

        return $meta;
    }

    protected function stripFrontMatter(string $markdown): string
    {
        return (string) preg_replace('/^---\R.*?\R---\R?/s', '', $markdown);
    }

    // -------------------------------------------------------------------
    // Permission gating
    // -------------------------------------------------------------------

    protected function allows(?Authenticatable $user, ?string $permission): bool
    {
        if ($permission === null || $permission === '') {
            return true;
        }

        return Gate::forUser($user)->allows($permission);
    }

    /**
     * Strip `<!-- kinetix:can ability -->…<!-- /kinetix:can -->` regions the
     * user is denied; unwrap allowed ones. Runs on RAW markdown before
     * rendering (the safe renderer drops HTML comments). An unclosed gate
     * strips to the end of the document. Nesting is not supported.
     */
    protected function applyBlockGates(string $markdown, ?Authenticatable $user): string
    {
        return (string) preg_replace_callback(
            '/<!--\s*kinetix:can\s+([\w.\-]+)\s*-->(.*?)(?:<!--\s*\/kinetix:can\s*-->|\z)/s',
            fn (array $matches): string => $this->allows($user, $matches[1]) ? $matches[2] : '',
            $markdown,
        );
    }

    // -------------------------------------------------------------------
    // Rendering helpers
    // -------------------------------------------------------------------

    /**
     * Base URL of the screenshot streaming endpoint, team segment included
     * when the route carries one.
     */
    protected function screenshotBaseUrl(): string
    {
        $route = RouteFacade::getRoutes()->getByName('kinetix.help.screenshot');

        if ($route === null) {
            return 'screenshots';
        }

        $params = ['file' => '__kx_file__'];

        if (in_array('current_team', $route->parameterNames(), true)) {
            $params['current_team'] = request()->route('current_team');
        }

        return rtrim(str_replace('__kx_file__', '', route('kinetix.help.screenshot', $params)), '/');
    }

    protected function firstHeading(string $markdown): ?string
    {
        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (str_starts_with($line, '# ')) {
                return trim(ltrim($line, '# '));
            }
        }

        return null;
    }

    /**
     * First body paragraph as plain text, clipped for list cards.
     */
    protected function firstParagraph(string $markdown): string
    {
        foreach (preg_split('/\R{2,}/', $markdown) ?: [] as $block) {
            $block = trim($block);

            if ($block === '' || str_starts_with($block, '#') || str_starts_with($block, '![') || str_starts_with($block, '<!--')) {
                continue;
            }

            return Str::limit($this->plainText($block), 160);
        }

        return '';
    }

    /**
     * Rough markdown → plain text (enough for excerpts and search).
     */
    protected function plainText(string $markdown): string
    {
        $text = (string) preg_replace('/<!--.*?-->/s', ' ', $markdown);
        $text = (string) preg_replace('/!\[[^\]]*\]\([^)]*\)/', ' ', $text);
        $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text);
        $text = (string) preg_replace('/[#>*_`~-]+/', ' ', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    protected function excerptAround(string $text, int $position, int $length): string
    {
        $start   = max(0, $position - 60);
        $excerpt = mb_substr($text, $start, 60 + $length + 60);

        return ($start > 0 ? '…' : '').trim($excerpt).'…';
    }
}
