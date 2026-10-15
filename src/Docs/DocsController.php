<?php

declare(strict_types=1);

namespace Happones\Kinetix\Docs;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The docs pages: `/{prefix}` opens the first section, `/{prefix}/{section}`
 * its landing page (or its first page), `/{prefix}/{section}/{page}` a page,
 * all rendered into the host's `docs.view` page, which mounts `<KinetixDocs>`.
 * `/{prefix}/search.json` feeds the search box.
 */
class DocsController
{
    /**
     * Resolved per call, not injected: a route keeps its controller instance
     * between requests, and the manager is per request (scoped).
     */
    protected function docs(): DocsManager
    {
        return app(DocsManager::class);
    }

    public function index(): RedirectResponse
    {
        $first = array_key_first($this->docs()->sections());

        abort_if($first === null, 404);

        return redirect($this->docs()->url($first));
    }

    public function section(Request $request): Response|RedirectResponse
    {
        $section = $this->docs()->section((string) $request->route('section')) ?? abort(404);
        $landing = $section->landing();

        if ($landing === null) {
            return redirect($this->docs()->url($section->key, $section->pages[0]->slug));
        }

        return $this->render($request, $section, $landing);
    }

    public function show(Request $request): Response
    {
        $section = $this->docs()->section((string) $request->route('section')) ?? abort(404);
        $slug    = (string) $request->route('page');
        $page    = $slug === '' ? null : $this->docs()->find($section->key, $slug);

        return $this->render($request, $section, $page ?? abort(404));
    }

    public function search(Request $request): JsonResponse
    {
        $locale = $this->docs()->locale();

        return response()
            ->json(['data' => $this->docs()->searchIndex($locale, $request)])
            ->header('Content-Language', $locale)
            ->header('Vary', 'Accept-Language, Cookie');
    }

    protected function render(Request $request, DocsSection $section, DocsPage $page): Response
    {
        $rendered = $this->docs()->render($page, $request);
        $position = array_search($page, $section->pages, true);
        $link     = fn (?DocsPage $other): ?array => $other === null ? null : [
            'title' => $other->title,
            'url'   => $this->docs()->url($section->key, $other->slug),
        ];
        $title = $page->isLanding() ? $section->title : "{$page->title} · {$section->title}";

        return Inertia::render((string) config('kinetix.docs.view', 'Kinetix/Docs'), [
            'docs' => [
                'sections' => array_values(array_map(fn (DocsSection $each): array => [
                    'key'    => $each->key,
                    'title'  => $each->title,
                    'url'    => $this->docs()->url($each->key),
                    'active' => $each->key === $section->key,
                ], $this->docs()->sections())),
                'section'    => ['key' => $section->key, 'title' => $section->title],
                'navigation' => $this->navigation($section, $page),
                'page'       => [
                    'slug'        => $page->slug,
                    'title'       => $page->title,
                    'description' => $page->description,
                    'group'       => $page->group,
                    'html'        => $rendered->html,
                    'toc'         => $rendered->toc,
                    'locale'      => $page->locale,
                    'fallback'    => $page->fallback,
                    'updatedAt'   => $page->updatedAt() !== null ? date(DATE_ATOM, (int) $page->updatedAt()) : null,
                    'editUrl'     => $this->editUrl($page),
                ],
                'previous'  => $link(is_int($position) ? ($section->pages[$position - 1] ?? null) : null),
                'next'      => $link(is_int($position) ? ($section->pages[$position + 1] ?? null) : null),
                'searchUrl' => route(config('kinetix.docs.routes.name', 'docs.').'search', [], false),
                'meta'      => [
                    'title'       => $title,
                    'description' => $page->description !== '' ? $page->description : $section->description,
                    'url'         => $request->url(),
                ],
            ],
        ])->withViewData(['kinetixDocs' => [
            'title'       => $title,
            'description' => $page->description !== '' ? $page->description : $section->description,
            'url'         => $request->url(),
        ]]);
    }

    /**
     * The section's pages by group, groups in the order their first page
     * appears (the landing page heads an untitled group).
     *
     * @return array<int, array{title: string, pages: array<int, array{title: string, url: string, active: bool}>}>
     */
    protected function navigation(DocsSection $section, DocsPage $current): array
    {
        $groups = [];

        foreach ($section->pages as $page) {
            $groups[$page->group] ??= ['title' => $page->group, 'pages' => []];
            $groups[$page->group]['pages'][] = [
                'title'  => $page->title,
                'url'    => $this->docs()->url($section->key, $page->slug),
                'active' => $page === $current,
            ];
        }

        return array_values($groups);
    }

    /**
     * `docs.edit_url` with `{path}` replaced by the file's path inside the docs
     * directory: a link to edit the page on your code host.
     */
    protected function editUrl(DocsPage $page): ?string
    {
        $template = config('kinetix.docs.edit_url');

        if (! is_string($template) || $template === '') {
            return null;
        }

        return str_replace('{path}', ltrim(substr($page->path, strlen($this->docs()->path())), '/'), $template);
    }
}
