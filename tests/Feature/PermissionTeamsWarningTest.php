<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionServiceProvider;

class PermissionTeamsWarningTest extends TestCase
{
    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PermissionServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.permissions.enabled', true);

        // The misconfiguration under test: Kinetix team scoping on, spatie's off.
        $app['config']->set('kinetix.permissions.teams', true);
        $app['config']->set('permission.teams', false);

        Log::spy();
    }

    public function test_a_warning_is_logged_when_spatie_team_scoping_is_off(): void
    {
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'permission.teams'))
            ->once();
    }
}
