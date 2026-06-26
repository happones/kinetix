<?php

declare(strict_types=1);

namespace Happones\Kinetix\Settings;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Renders the settings pages and persists their forms. Gated by the
 * `settings.manage` ability. Pages are resolved by route-parameter name so the
 * optional `{current_team}` prefix can't shift positional arguments.
 */
class SettingsController
{
    public function index(Request $request): InertiaResponse|JsonResponse
    {
        Gate::authorize('settings.manage');

        $pages = $this->pages();

        abort_if($pages === [], 404, 'No settings pages registered.');

        // JSON (the SPA / an embedded tab) gets the page list + the first page.
        if ($request->wantsJson()) {
            return response()->json([
                'pages'  => $this->pageList(),
                'active' => $pages[0]->toArray(),
            ]);
        }

        return $this->render($pages[0]);
    }

    public function show(Request $request): InertiaResponse|JsonResponse
    {
        Gate::authorize('settings.manage');

        $page = $this->resolve($request);

        // Lets <KinetixSettingsForm page-key="…"> self-load when embedded in the
        // host's own settings layout (no host controller needed).
        if ($request->wantsJson()) {
            return response()->json($page->toArray());
        }

        return $this->render($page);
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        $state = $this->resolve($request)->save($request->all());

        return response()->json(['status' => 'success', 'data' => $state]);
    }

    /**
     * @return array<int, SettingsPage>
     */
    protected function pages(): array
    {
        return array_map(
            static fn (string $class): SettingsPage => $class::make(),
            app(SettingsRegistry::class)->pages(),
        );
    }

    protected function resolve(Request $request): SettingsPage
    {
        $class = app(SettingsRegistry::class)->find((string) $request->route('page'));

        abort_if($class === null, 404, 'Unknown settings page.');

        return $class::make();
    }

    protected function render(SettingsPage $active): InertiaResponse
    {
        return Inertia::render(
            (string) config('kinetix.settings.view', 'Kinetix/Settings'),
            [
                'pages'  => $this->pageList(),
                'active' => $active->toArray(),
            ],
        );
    }

    /**
     * Lightweight metadata for every registered page (for tab/nav rendering).
     *
     * @return array<int, array{key: string, title: string, icon: string}>
     */
    protected function pageList(): array
    {
        return array_map(
            static fn (SettingsPage $page): array => [
                'key'   => $page->key(),
                'title' => $page->title(),
                'icon'  => $page->navigationIcon(),
            ],
            $this->pages(),
        );
    }
}
