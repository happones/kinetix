<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * The override detection is a development aid: outside `local` nothing is
 * registered, so an app that intentionally replaces a prop pays no runtime cost
 * and gets no log noise.
 */
class InertiaShareOverrideProductionTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['env'] = 'production';

        Log::spy();
    }

    protected function defineRoutes($router): void
    {
        $router->get('/probe', fn () => 'ok');
    }

    public function test_no_warning_outside_local(): void
    {
        Inertia::share('kinetix_permissions', ['mine' => true]);

        $this->get('/probe')->assertOk();

        Log::shouldNotHaveReceived('warning');
    }
}
