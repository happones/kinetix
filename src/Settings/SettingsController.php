<?php

declare(strict_types=1);

namespace Happones\Kinetix\Settings;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Renders the settings pages and persists their forms. Each page is gated by
 * its own {@see SettingsPage::authorize()} (`settings.manage` by default), and
 * a user is only shown the pages they may open. Pages are resolved by
 * route-parameter name so the optional `{current_team}` prefix can't shift
 * positional arguments.
 */
class SettingsController
{
    public function index(Request $request): InertiaResponse|JsonResponse
    {
        abort_if($this->registered() === [], 404, 'No settings pages registered.');

        $pages = $this->allowed($request->user());

        abort_if($pages === [], 403);

        // JSON (the SPA / an embedded tab) gets the page list + the first page.
        if ($request->wantsJson()) {
            return response()->json([
                'pages'  => $this->pageList($pages),
                'active' => $pages[0]->toArray(),
            ]);
        }

        return $this->render($pages[0], $pages);
    }

    public function show(Request $request): InertiaResponse|JsonResponse
    {
        $page = $this->resolve($request);

        // Lets <KinetixSettingsForm page-key="…"> self-load when embedded in the
        // host's own settings layout (no host controller needed).
        if ($request->wantsJson()) {
            return response()->json($page->toArray());
        }

        return $this->render($page, $this->allowed($request->user()));
    }

    public function update(Request $request): JsonResponse
    {
        $state = $this->resolve($request)->save($request->all());

        return response()->json(['status' => 'success', 'data' => $state]);
    }

    /**
     * @return array<int, SettingsPage>
     */
    protected function registered(): array
    {
        return array_map(
            static fn (string $class): SettingsPage => $class::make(),
            app(SettingsRegistry::class)->pages(),
        );
    }

    /**
     * The pages the user may open.
     *
     * @return array<int, SettingsPage>
     */
    protected function allowed(?Authenticatable $user): array
    {
        return array_values(array_filter(
            $this->registered(),
            static fn (SettingsPage $page): bool => $page->authorize($user),
        ));
    }

    /**
     * The page the route names, once the user may open it.
     */
    protected function resolve(Request $request): SettingsPage
    {
        $class = app(SettingsRegistry::class)->find((string) $request->route('page'));

        abort_if($class === null, 404, 'Unknown settings page.');

        $page = $class::make();

        abort_unless($page->authorize($request->user()), 403);

        return $page;
    }

    /**
     * @param array<int, SettingsPage> $pages the pages the user may open
     */
    protected function render(SettingsPage $active, array $pages): InertiaResponse
    {
        return Inertia::render(
            (string) config('kinetix.settings.view', 'Kinetix/Settings'),
            [
                'pages'  => $this->pageList($pages),
                'active' => $active->toArray(),
            ],
        );
    }

    /**
     * Lightweight metadata for the pages (for tab/nav rendering).
     *
     * @param  array<int, SettingsPage>                                    $pages
     * @return array<int, array{key: string, title: string, icon: string}>
     */
    protected function pageList(array $pages): array
    {
        return array_map(
            static fn (SettingsPage $page): array => [
                'key'   => $page->key(),
                'title' => $page->title(),
                'icon'  => $page->navigationIcon(),
            ],
            $pages,
        );
    }
}
