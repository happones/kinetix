<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionServiceProvider;

class RoutesCommandTest extends TestCase
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

        // Teams on, so the listing must show the {current_team} segment — the
        // part of the contract hosts get wrong.
        $app['config']->set('kinetix.teams', true);
        $app['config']->set('kinetix.permissions.enabled', true);
        $app['config']->set('kinetix.membership.enabled', true);
    }

    public function test_it_states_the_prefix_the_frontend_calls(): void
    {
        $this->artisan('kinetix:routes')
            ->expectsOutputToContain('{current_team}/_kinetix')
            ->assertSuccessful();
    }

    public function test_it_lists_the_resolved_kinetix_endpoints(): void
    {
        $uris = array_column($this->routesAsJson(null), 'uri');

        $this->assertContains('/{current_team}/_kinetix/permissions/roles', $uris);
        $this->assertContains('/{current_team}/_kinetix/members', $uris);
    }

    public function test_the_filter_narrows_the_listing(): void
    {
        $routes = $this->routesAsJson('members');

        $this->assertNotSame([], $routes);

        foreach ($routes as $route) {
            $this->assertStringContainsString('members', $route['uri'].$route['name']);
        }
    }

    public function test_every_listed_uri_carries_the_team_segment_and_prefix(): void
    {
        $routes = $this->routesAsJson('permissions');

        $this->assertNotSame([], $routes);

        foreach ($routes as $route) {
            $this->assertStringStartsWith('/{current_team}/_kinetix/', $route['uri']);
        }
    }

    public function test_it_reports_host_routes_that_collide_with_the_prefix(): void
    {
        Route::get('{current_team}/_kinetix/members', fn () => 'host')->name('app.members');

        $names = array_column($this->routesAsJson(null), 'name');

        $this->assertContains('app.members', $names);
    }

    public function test_it_reports_the_configured_prefix_when_nothing_matches(): void
    {
        config()->set('kinetix.route_prefix', 'kinetix-api');

        $this->artisan('kinetix:routes', ['filter' => 'nothing-matches-this'])
            ->expectsOutputToContain('kinetix-api')
            ->assertSuccessful();
    }

    /**
     * @return array<int, array{method: string, uri: string, name: string|null, middleware: array<int, string>}>
     */
    private function routesAsJson(?string $filter): array
    {
        $this->withoutMockingConsoleOutput();

        Artisan::call('kinetix:routes', array_filter([
            'filter' => $filter,
            '--json' => true,
        ]));

        /** @var array<int, array{method: string, uri: string, name: string|null, middleware: array<int, string>}> $decoded */
        $decoded = json_decode(Artisan::output(), true) ?? [];

        return $decoded;
    }
}
