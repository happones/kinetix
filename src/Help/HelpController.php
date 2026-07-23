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
 */
class HelpController
{
    public function __construct(protected HelpManager $manager) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'articles' => array_map(
                static fn (HelpArticle $article): array => $article->toSummary(),
                $this->manager->articles($request->user()),
            ),
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        // Resolve by name (not positionally): with teams enabled the route
        // gains a leading `{current_team}` param.
        $slug    = (string) $request->route('slug');
        $article = $this->manager->find($slug, $request->user());

        abort_if($article === null, 404);

        $ordered  = $this->manager->articles($request->user());
        $position = array_search($article->slug, array_column($ordered, 'slug'), true);
        $previous = is_int($position) && $position > 0 ? $ordered[$position - 1] : null;
        $next     = is_int($position) && $position < count($ordered) - 1 ? $ordered[$position + 1] : null;

        return response()->json([
            'slug'  => $article->slug,
            'title' => $article->title,
            'group' => $article->group,
            'html'  => $this->manager->render($article, $request->user()),
            'prev'  => $previous ? ['slug' => $previous->slug, 'title' => $previous->title] : null,
            'next'  => $next ? ['slug' => $next->slug, 'title' => $next->title] : null,
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        return response()->json([
            'results' => $this->manager->search(
                (string) $request->query('q', ''),
                $request->user(),
            ),
        ]);
    }

    /**
     * Stream a screenshot: from the configured disk first (S3/private disks
     * work because the response proxies through this authenticated route),
     * falling back to `{help.path}/screenshots/` for the commit-the-PNGs
     * workflow. Filenames are strictly validated (no traversal, images only).
     */
    public function screenshot(Request $request): Response
    {
        $name = basename((string) $request->route('file'));

        abort_unless((bool) preg_match('/^[\w\-.]+\.(png|jpe?g|webp)$/i', $name), 404);

        $disk   = config('kinetix.help.screenshots.disk') ?? config('kinetix.filesystem.disk', 'public');
        $prefix = trim((string) config('kinetix.help.screenshots.path_prefix', 'help/screenshots'), '/');
        $key    = "{$prefix}/{$name}";

        if (Storage::disk($disk)->exists($key)) {
            return Storage::disk($disk)->response($key);
        }

        $local = $this->manager->path()."/screenshots/{$name}";

        abort_unless(is_file($local), 404);

        return response()->file($local);
    }
}
