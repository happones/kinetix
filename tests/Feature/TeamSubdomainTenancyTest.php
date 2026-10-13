<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Billing\BillingRoutes;
use Happones\Kinetix\Support\KinetixTeams;
use Happones\Kinetix\Tests\Concerns\ResolvesTeamRoutes;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/**
 * `kinetix.tenancy.domain`: the team rides in the host (`acme.example.test`)
 * instead of a `{current_team}/` path segment. The parameter keeps its name in
 * the domain, so route() and everything reading the route's team behave the
 * same in both modes.
 */
class TeamSubdomainTenancyTest extends TestCase
{
    use ResolvesTeamRoutes;

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.teams', true);
        $app['config']->set('kinetix.tenancy.domain', 'example.test');
    }

    public function test_team_routes_are_bound_to_the_team_subdomain(): void
    {
        $route = Route::getRoutes()->getByName('kinetix.tables.cell-update');

        $this->assertNotNull($route);
        $this->assertSame('{current_team}.example.test', $route->getDomain());
        $this->assertStringStartsWith('_kinetix/tables', $route->uri());
    }

    public function test_urls_are_built_on_the_team_subdomain(): void
    {
        $this->assertStringStartsWith(
            'http://acme.example.test/_kinetix/tables',
            route('kinetix.tables.cell-update', ['current_team' => 'acme']),
        );
    }

    public function test_the_team_is_read_from_the_host(): void
    {
        KinetixTeams::routes(['web'])->get('probe', fn () => (string) KinetixTeams::currentRouteKey());
        Route::getRoutes()->refreshNameLookups();

        $this->get('http://acme.example.test/probe')->assertOk()->assertSee('acme');
        $this->get('http://example.test/probe')->assertNotFound();
    }

    public function test_routes_without_a_team_stay_on_every_host(): void
    {
        $route = Route::getRoutes()->getByName('kinetix.flash.dismiss');

        $this->assertNotNull($route);
        $this->assertNull($route->getDomain());
    }

    public function test_the_shared_prefix_drops_the_team_and_says_it_is_in_the_host(): void
    {
        $this->withTeamSegment('acme');

        $shared = Inertia::getShared('kinetix_config');
        $config = is_callable($shared) ? $shared() : $shared;

        $this->assertSame('_kinetix', $config['route_prefix']);
        $this->assertSame('acme', $config['team']);
        $this->assertTrue($config['team_in_host']);
    }

    public function test_billing_routes_keep_their_team_parameter_in_the_host(): void
    {
        BillingRoutes::register();
        Route::getRoutes()->refreshNameLookups();

        $route = Route::getRoutes()->getByName('billing.index');

        $this->assertSame('{team}.example.test', $route?->getDomain());
        $this->assertSame('billing', $route?->uri());
        $this->assertSame('http://acme.example.test/billing', route('billing.index', ['team' => 'acme']));
    }

    public function test_without_teams_the_domain_is_ignored(): void
    {
        config()->set('kinetix.teams', false);

        $this->assertFalse(KinetixTeams::inHost());
        $this->assertSame('_kinetix', KinetixTeams::routePrefix('_kinetix'));
    }

    public function test_with_the_team_in_the_path_nothing_changes(): void
    {
        config()->set('kinetix.tenancy.domain', null);

        $this->assertFalse(KinetixTeams::inHost());
        $this->assertSame('{current_team}/_kinetix', KinetixTeams::routePrefix('_kinetix'));
    }
}
