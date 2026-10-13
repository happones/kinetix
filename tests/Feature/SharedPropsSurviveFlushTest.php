<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Inertia\Inertia;

/**
 * A process that flushes Inertia's shared data between requests — a
 * browser-test server does before every request — served pages without
 * Kinetix's props: no permission-gated menus, no route prefix.
 */
class SharedPropsSurviveFlushTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->middleware('web')->get('/probe', fn () => Inertia::render('Probe'));
    }

    private function props(): array
    {
        return $this->withHeaders(['X-Inertia' => 'true'])
            ->get('/probe')
            ->assertOk()
            ->json('props');
    }

    public function test_the_props_are_shared_again_after_a_flush(): void
    {
        Inertia::flushShared();

        $props = $this->props();

        $this->assertArrayHasKey('kinetix_config', $props);
        $this->assertArrayHasKey('kinetix_permissions', $props);
    }

    public function test_a_prop_the_app_replaced_is_left_to_the_override_guard(): void
    {
        Inertia::share('kinetix_config', ['mine' => true]);

        $this->assertSame(['mine' => true], $this->props()['kinetix_config']);
    }
}
