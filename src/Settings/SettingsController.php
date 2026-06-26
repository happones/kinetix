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
    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('settings.manage');

        $pages = $this->pages();

        abort_if($pages === [], 404, 'No settings pages registered.');

        return $this->render($pages[0]);
    }

    public function show(Request $request): InertiaResponse
    {
        Gate::authorize('settings.manage');

        return $this->render($this->resolve($request));
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
                'pages' => array_map(
                    static fn (SettingsPage $page): array => [
                        'key'   => $page->key(),
                        'title' => $page->title(),
                        'icon'  => $page->navigationIcon(),
                    ],
                    $this->pages(),
                ),
                'active' => $active->toArray(),
            ],
        );
    }
}
