<?php

declare(strict_types=1);

namespace Happones\Kinetix\Help;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON endpoints behind the Help Center components. Everything is filtered
 * through {@see HelpManager}'s permission gating; unknown and unauthorized
 * articles are indistinguishable (404).
 *
 * Every endpoint takes an optional `?locale=` — validated against the locales
 * the manual is authored in — so the SPA can ask for a language explicitly
 * instead of relying on the ambient app locale. That also makes the URL vary
 * per language, which keeps browser/CDN caches from serving one language's
 * payload to another; responses carry `Content-Language` and `Vary` to match.
 */
class HelpController
{
    public function __construct(protected HelpManager $manager) {}

    public function index(Request $request): JsonResponse
    {
        $locale = $this->manager->locale($this->requestedLocale($request));

        return $this->localized(response()->json([
            'locale'   => $locale,
            'locales'  => $this->manager->supportedLocales(),
            'articles' => array_map(
                static fn (HelpArticle $article): array => $article->toSummary(),
                $this->manager->articles($request->user(), $locale),
            ),
        ]), $locale);
    }

    public function show(Request $request): JsonResponse
    {
        // Resolve by name (not positionally): with teams enabled the route
        // gains a leading `{current_team}` param.
        $slug    = (string) $request->route('slug');
        $locale  = $this->manager->locale($this->requestedLocale($request));
        $article = $this->manager->find($slug, $request->user(), $locale);

        abort_if($article === null, 404);

        $ordered  = $this->manager->articles($request->user(), $locale);
        $position = array_search($article->slug, array_column($ordered, 'slug'), true);
        $previous = is_int($position) && $position > 0 ? $ordered[$position - 1] : null;
        $next     = is_int($position) && $position < count($ordered) - 1 ? $ordered[$position + 1] : null;

        return $this->localized(response()->json([
            'slug'             => $article->slug,
            'title'            => $article->title,
            'group'            => $article->group,
            'html'             => $this->manager->render($article, $request->user(), $locale),
            'locale'           => $article->locale,
            'requestedLocale'  => $locale,
            'isFallback'       => $article->isFallback,
            'availableLocales' => $this->manager->availableLocales($article->slug),
            'prev'             => $previous ? ['slug' => $previous->slug, 'title' => $previous->title] : null,
            'next'             => $next ? ['slug' => $next->slug, 'title' => $next->title] : null,
        ]), $article->locale);
    }

    public function search(Request $request): JsonResponse
    {
        $locale = $this->manager->locale($this->requestedLocale($request));

        return $this->localized(response()->json([
            'locale'  => $locale,
            'results' => $this->manager->search(
                (string) $request->query('q', ''),
                $request->user(),
                $locale,
            ),
        ]), $locale);
    }

    /**
     * Stream a screenshot: the localized capture (`{prefix}/{locale}/{name}`)
     * first, then the shared one, from the configured disk (S3/private disks
     * work because the response proxies through this authenticated route) and
     * finally from `{help.path}/screenshots/` for the commit-the-PNGs
     * workflow. Filenames are strictly validated (no traversal, images only).
     */
    public function screenshot(Request $request): Response
    {
        $name = basename((string) $request->route('file'));

        abort_unless((bool) preg_match('/^[\w\-.]+\.(png|jpe?g|webp)$/i', $name), 404);

        $locale = $this->manager->locale($this->requestedLocale($request));
        $disk   = config('kinetix.help.screenshots.disk') ?? config('kinetix.filesystem.disk', 'public');
        $prefix = trim((string) config('kinetix.help.screenshots.path_prefix', 'help/screenshots'), '/');

        foreach (["{$prefix}/{$locale}/{$name}", "{$prefix}/{$name}"] as $key) {
            if (Storage::disk($disk)->exists($key)) {
                return Storage::disk($disk)->response($key);
            }
        }

        foreach (["/screenshots/{$locale}/{$name}", "/screenshots/{$name}"] as $suffix) {
            $local = $this->manager->path().$suffix;

            if (is_file($local)) {
                return response()->file($local);
            }
        }

        abort(404);
    }

    /**
     * The requested locale, or null when the caller didn't ask for one (the
     * manager then falls back to the application's).
     */
    protected function requestedLocale(Request $request): ?string
    {
        $locale = $request->query('locale');

        return is_string($locale) && $locale !== '' ? $locale : null;
    }

    /**
     * Tag the payload with the language it is in, so caches key on it.
     */
    protected function localized(JsonResponse $response, string $locale): JsonResponse
    {
        return $response
            ->header('Content-Language', $locale)
            ->header('Vary', 'Accept-Language');
    }
}
