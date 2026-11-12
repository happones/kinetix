<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Testing\TestResponse;
use Inertia\AlwaysProp;
use Inertia\Inertia;

/**
 * A table refresh that asks only for `table` (the frontend's `reloadOnly`)
 * must skip every other prop, shared ones included, yet still deliver the
 * one-shot toast and flash a mutation left in the session.
 */
class InertiaPartialTableReloadTest extends TestCase
{
    public int $sharedCalls = 0;

    public int $siblingCalls = 0;

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->get('/probe', fn () => Inertia::render('Probe', [
            'table'   => fn () => ['rows' => 1],
            'sibling' => function (): string {
                $this->siblingCalls++;

                return 'sibling';
            },
        ]));

        $router->middleware('web')->post('/probe-mutate', function () {
            session()->flash('kinetix_toast', 'Saved.');

            return response()->json(['ok' => true]);
        });

        $router->middleware('web')->get('/probe-flash', function () {
            session()->flash('kinetix_toast', 'Saved.');

            return Inertia::render('Probe', ['table' => fn () => ['rows' => 1]]);
        });
    }

    private function partial(string ...$only): TestResponse
    {
        return $this->withHeaders([
            'X-Inertia'                   => 'true',
            'X-Inertia-Partial-Component' => 'Probe',
            'X-Inertia-Partial-Data'      => implode(',', $only),
        ])->get('/probe');
    }

    public function test_a_partial_reload_returns_only_the_table_and_skips_other_closures(): void
    {
        Inertia::share('probe_shared', function (): string {
            $this->sharedCalls++;

            return 'shared';
        });

        $props = $this->partial('table')->assertOk()->json('props');

        $this->assertSame(['rows' => 1], $props['table']);
        $this->assertArrayNotHasKey('sibling', $props);
        $this->assertArrayNotHasKey('probe_shared', $props);
        $this->assertSame(0, $this->siblingCalls);
        $this->assertSame(0, $this->sharedCalls);
    }

    public function test_a_full_visit_still_returns_everything(): void
    {
        $props = $this->withHeader('X-Inertia', 'true')->get('/probe')->assertOk()->json('props');

        $this->assertSame('sibling', $props['sibling']);
        $this->assertArrayHasKey('kinetix_config', $props);
    }

    public function test_a_toast_flashed_by_a_mutation_survives_a_partial_reload(): void
    {
        $props = $this->withHeaders([
            'X-Inertia'                   => 'true',
            'X-Inertia-Partial-Component' => 'Probe',
            'X-Inertia-Partial-Data'      => 'table',
        ])->get('/probe-flash')->assertOk()->json('props');

        $this->assertSame('Saved.', $props['kinetix_toast']['message']);
    }

    public function test_a_toast_flashed_by_a_json_endpoint_arrives_on_the_following_partial_reload(): void
    {
        $this->postJson('/probe-mutate')->assertOk();

        $props = $this->partial('table')->assertOk()->json('props');

        $this->assertSame('Saved.', $props['kinetix_toast']['message']);
    }

    public function test_the_legacy_flash_prop_is_an_always_prop_too(): void
    {
        $this->assertInstanceOf(AlwaysProp::class, Inertia::getShared()['kinetix_flash'] ?? null);
    }
}
