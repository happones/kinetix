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
 *    (regional locales fall back `pt_BR` → `pt` → `help.fallback_locale` →
 *    base file);
 *  - optional flat front matter: title / permission / icon / order / group;
 *  - screenshots referenced as `![Alt](screenshots/name.png)` are rewritten to
 *    the streaming endpoint at render time, carrying the article's locale so
 *    localized captures (`screenshots/{locale}/name.png`) win when present;
 *  - `<!-- kinetix:can ability -->…<!-- /kinetix:can -->` blocks are stripped
 *    for users the Gate denies (processed on the RAW markdown, before
 *    rendering — the safe renderer drops HTML comments).
 *
 * **Locale is a first-class argument**, never an ambient assumption: every
 * read takes an optional locale that defaults to the application's. Callers
 * (the JSON endpoints) resolve it through {@see self::locale()}, which only
 * accepts codes the manual is actually authored in — so a request can ask for
 * a language without the app locale having to change, and untrusted input can
 * never widen the cache keyspace.
 *
 * Each locale's articles are built ONCE per request into an index (metadata +
 * the plain-text body used by search), so a search costs zero extra file
 * reads. That index may also be cached (`kinetix.help.cache`); rendered HTML
 * is NEVER cached because its content is per-user (permission gating).
 */
class HelpManager
{
    /**
     * Per-request index memo, keyed by locale.
     *
     * @var array<string, array{articles: array<int, HelpArticle>, bodies: array<string, string>}>
     */
    protected array $indexes = [];

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

    // -------------------------------------------------------------------
    // Locale resolution
    // -------------------------------------------------------------------

    /**
     * Resolve the locale to serve: the requested one when the manual is
     * authored in it, else the application's. Unsupported input is ignored
     * rather than trusted — this value keys caches and picks files.
     */
    public function locale(?string $requested = null): string
    {
        $requested = is_string($requested) ? trim($requested) : '';

        if ($requested !== '' && in_array($requested, $this->supportedLocales(), true)) {
            return $requested;
        }

        return (string) app()->getLocale();
    }

    /**
     * The locales the manual may be served in, in preference order: the
     * explicit `help.locales` allow-list, else the Locale module's configured
     * locales, else whatever the article files themselves offer (plus the
     * app's own locale and fallback). Always a bounded, disk- or
     * config-derived set — never free-form user input.
     *
     * @return array<int, string>
     */
    public function supportedLocales(): array
    {
        $configured = config('kinetix.help.locales');

        if (is_array($configured) && $configured !== []) {
            return array_values(array_unique(array_map('strval', $configured)));
        }

        $fromModule = config('kinetix.locale.locales');

        if (is_array($fromModule) && $fromModule !== []) {
            return array_values(array_unique(array_map('strval', array_keys($fromModule))));
        }

        return array_values(array_unique(array_filter([
            ...$this->authoredLocales(),
            (string) app()->getLocale(),
            (string) config('app.fallback_locale', 'en'),
        ])));
    }

    /**
     * Locale codes discovered from the variant filenames on disk.
     *
     * @return array<int, string>
     */
    public function authoredLocales(): array
    {
        $locales = [];

        foreach ($this->allMarkdownFiles() as $path) {
            if (preg_match('/\.([a-z]{2}(?:[_-][A-Za-z]{2,4})?)\.md$/', basename($path), $matches)) {
                $locales[] = $matches[1];
            }
        }

        return array_values(array_unique($locales));
    }

    /**
     * The locales a single article is authored in — the base file's locale
     * (the app's fallback) plus every variant present on disk.
     *
     * @return array<int, string>
     */
    public function availableLocales(string $slug): array
    {
        $slug     = basename($slug);
        $locales  = [];
        $baseCode = $this->baseLocale();

        if (is_file($this->path()."/{$slug}.md")) {
            $locales[] = $baseCode;
        }

        foreach ($this->supportedLocales() as $locale) {
            if (is_file($this->path()."/{$slug}.{$locale}.md")) {
                $locales[] = $locale;
            }
        }

        return array_values(array_unique($locales));
    }

    /**
     * The language the base `{slug}.md` files are written in
     * (`help.fallback_locale`, else the app's fallback locale).
     */
    public function baseLocale(): string
    {
        $configured = config('kinetix.help.fallback_locale');

        return is_string($configured) && $configured !== ''
            ? $configured
            : (string) config('app.fallback_locale', 'en');
    }

    // -------------------------------------------------------------------
    // Reads
    // -------------------------------------------------------------------

    /**
     * The authorized articles for a user, ordered (front matter `order`, then
     * slug). Pass null to resolve for a guest (only ungated articles).
     *
     * With `help.hide_untranslated` on, articles that fall back to another
     * language are omitted instead of served in the wrong one.
     *
     * @return array<int, HelpArticle>
     */
    public function articles(?Authenticatable $user = null, ?string $locale = null): array
    {
        $hideUntranslated = (bool) config('kinetix.help.hide_untranslated', false);

        return array_values(array_filter(
            $this->index($this->locale($locale))['articles'],
            fn (HelpArticle $article): bool => $this->allows($user, $article->permission)
                && (! $hideUntranslated || ! $article->isFallback),
        ));
    }

    /**
     * Find an authorized article by slug. Returns null both for unknown slugs
     * and for articles the user may not see (a 404, never a 403 — gated
     * articles must not leak their existence).
     */
    public function find(string $slug, ?Authenticatable $user = null, ?string $locale = null): ?HelpArticle
    {
        $slug = basename($slug);

        foreach ($this->articles($user, $locale) as $article) {
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
     * to the streaming endpoint for the article's own locale.
     */
    public function render(HelpArticle $article, ?Authenticatable $user = null, ?string $locale = null): string
    {
        $resolved = $this->resolveFile($article->slug, $this->locale($locale));
        $markdown = (string) file_get_contents($resolved['path']);
        $markdown = $this->stripFrontMatter($markdown);
        $markdown = $this->applyBlockGates($markdown, $user);

        $html = (string) Str::markdown($markdown, [
            'html_input'         => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return $this->rewriteScreenshots($html, $resolved['locale']);
    }

    /**
     * Case-insensitive search over the authorized articles' localized titles
     * and bodies, with a plain-text excerpt around the first match. Runs
     * entirely on the in-memory index — no file reads per query.
     *
     * @return array<int, array{slug: string, title: string, group: ?string, excerpt: string, locale: string, isFallback: bool}>
     */
    public function search(string $query, ?Authenticatable $user = null, ?string $locale = null): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        $locale  = $this->locale($locale);
        $bodies  = $this->index($locale)['bodies'];
        $results = [];

        foreach ($this->articles($user, $locale) as $article) {
            $body = $bodies[$article->slug] ?? '';

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
                'locale'     => $article->locale,
                'isFallback' => $article->isFallback,
            ];
        }

        return $results;
    }

    /**
     * Resolve the markdown file for a locale: `{slug}.{locale}.md`, then the
     * language part of a regional locale (`pt_BR` → `pt`), then the configured
     * fallback locale, then the base `{slug}.md`.
     */
    public function localizedPath(string $slug, ?string $locale = null): string
    {
        return $this->resolveFile($slug, $this->locale($locale))['path'];
    }

    /**
     * The locale actually served for an article (the base locale when it falls
     * back to `{slug}.md`).
     */
    public function localeFor(string $slug, ?string $locale = null): string
    {
        return $this->resolveFile($slug, $this->locale($locale))['locale'];
    }

    /**
     * The file that serves a slug in a locale, and the locale it is actually
     * written in — resolved together so the candidate chain is walked once.
     *
     * @return array{path: string, locale: string}
     */
    protected function resolveFile(string $slug, string $locale): array
    {
        $slug = basename($slug);

        foreach ($this->localeCandidates($locale) as $candidate) {
            $path = $this->path()."/{$slug}.{$candidate}.md";

            if (is_file($path)) {
                return ['path' => $path, 'locale' => $candidate];
            }
        }

        return ['path' => $this->path()."/{$slug}.md", 'locale' => $this->baseLocale()];
    }

    /**
     * Variant suffixes tried for a locale, most specific first.
     *
     * @return array<int, string>
     */
    protected function localeCandidates(string $locale): array
    {
        $candidates = [$locale];
        $language   = (string) preg_replace('/[_-].*$/', '', $locale);

        if ($language !== '' && $language !== $locale) {
            $candidates[] = $language;
        }

        $fallback = $this->baseLocale();

        if ($fallback !== '' && ! in_array($fallback, $candidates, true)) {
            $candidates[] = $fallback;
        }

        return $candidates;
    }

    // -------------------------------------------------------------------
    // Index (discovery + search corpus, one file read per article)
    // -------------------------------------------------------------------

    /**
     * The locale's article index: ordered metadata plus the plain-text body of
     * each article, built with a single pass over the files. Memoized per
     * request and optionally cached (`kinetix.help.cache`) — keyed on the
     * locale plus, for the default `fingerprint` strategy, the file set and
     * mtimes, so edits invalidate automatically. Set the strategy to `ttl` in
     * production to skip the per-request stat of every file.
     *
     * @return array{articles: array<int, HelpArticle>, bodies: array<string, string>}
     */
    protected function index(string $locale): array
    {
        $fingerprinted = config('kinetix.help.cache.strategy', 'fingerprint') !== 'ttl';

        // The memo is keyed on the files' mtimes, so an edit busts it — even in
        // a long-lived worker (Octane), where the manager outlives the request.
        // Under the `ttl` strategy there is no fingerprint to key on, so the
        // cache entry owns expiry and the memo is skipped.
        $memoKey = $fingerprinted ? $locale.'@'.$this->fingerprint() : null;

        if ($memoKey !== null && isset($this->indexes[$memoKey])) {
            return $this->indexes[$memoKey];
        }

        $files = $this->baseFiles();
        $index = $files === []
            ? ['articles' => [], 'bodies' => []]
            : $this->cachedIndex($files, $locale, $memoKey);

        if ($memoKey !== null) {
            $this->indexes[$memoKey] = $index;
        }

        return $index;
    }

    /**
     * @param  array<int, string>                                                      $files
     * @return array{articles: array<int, HelpArticle>, bodies: array<string, string>}
     */
    protected function cachedIndex(array $files, string $locale, ?string $memoKey): array
    {
        if (! config('kinetix.help.cache.enabled', false)) {
            return $this->buildIndex($files, $locale);
        }

        /** @var array{articles: array<int, HelpArticle>, bodies: array<string, string>} $index */
        $index = Cache::remember(
            'kinetix.help.index.'.($memoKey ?? $locale.'@ttl'),
            (int) config('kinetix.help.cache.ttl', 3600),
            fn (): array => $this->buildIndex($files, $locale),
        );

        return $index;
    }

    /**
     * Cache/memo discriminator: the file set + mtimes, so any edit to any
     * article (base or variant) invalidates every locale's index.
     */
    protected function fingerprint(): string
    {
        return md5(implode('|', array_map(
            static fn (string $path): string => $path.':'.(string) filemtime($path),
            $this->allMarkdownFiles(),
        )));
    }

    /**
     * @param  array<int, string>                                                      $files
     * @return array{articles: array<int, HelpArticle>, bodies: array<string, string>}
     */
    protected function buildIndex(array $files, string $locale): array
    {
        $articles = [];
        $bodies   = [];

        foreach ($files as $path) {
            $slug           = basename($path, '.md');
            $resolved       = $this->resolveFile($slug, $locale);
            $resolvedLocale = $resolved['locale'];
            $markdown       = (string) file_get_contents($resolved['path']);
            $meta           = $this->parseFrontMatter($markdown);
            $body           = $this->stripFrontMatter($markdown);

            $articles[] = new HelpArticle(
                slug: $slug,
                title: $meta['title'] ?? $this->firstHeading($body) ?? (string) str($slug)->after('-')->headline(),
                group: $meta['group'] ?? null,
                icon: $meta['icon']   ?? null,
                order: isset($meta['order']) ? (int) $meta['order'] : PHP_INT_MAX,
                permission: $meta['permission'] ?? null,
                excerpt: $this->firstParagraph($body),
                locale: $resolvedLocale,
                isFallback: $resolvedLocale !== $locale,
            );

            $bodies[$slug] = $this->plainText($body);
        }

        usort($articles, static function (HelpArticle $a, HelpArticle $b): int {
            return [$a->order, $a->slug] <=> [$b->order, $b->slug];
        });

        return ['articles' => $articles, 'bodies' => $bodies];
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
    /**
     * Point `![](screenshots/x.png)` embeds at the streaming endpoint, tagged
     * with the locale the article is written in so a localized capture
     * (`{prefix}/{locale}/x.png`) is served when one exists.
     */
    protected function rewriteScreenshots(string $html, string $locale): string
    {
        $base = $this->screenshotBaseUrl();

        return (string) preg_replace_callback(
            '/src="screenshots\/([\w\-.]+)"/',
            static fn (array $matches): string => 'src="'.$base.'/'.$matches[1].'?locale='.rawurlencode($locale).'"',
            $html,
        );
    }

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
