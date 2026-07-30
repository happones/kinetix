<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class InertiaShareOverrideWarningTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The detection only runs in local — production pays nothing.
        $app['env'] = 'local';

        Log::spy();
    }

    protected function defineRoutes($router): void
    {
        $router->get('/probe', fn () => 'ok');
    }

    public function test_it_warns_when_the_host_replaces_a_kinetix_prop(): void
    {
        // What a HandleInertiaRequests::share() returning its own
        // `kinetix_permissions` key does: array_merge puts the host's value last.
        Inertia::share('kinetix_permissions', ['mine' => true]);

        $this->get('/probe')->assertOk();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'kinetix_permissions')
                && str_contains($message, 'was replaced by the application'))
            ->once();
    }

    public function test_it_warns_only_once_per_process(): void
    {
        Inertia::share('kinetix_config', ['mine' => true]);

        $this->get('/probe')->assertOk();
        $this->get('/probe')->assertOk();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'kinetix_config'))
            ->once();
    }

    public function test_it_stays_quiet_when_the_props_are_untouched(): void
    {
        $this->get('/probe')->assertOk();

        Log::shouldNotHaveReceived('warning');
    }
}
