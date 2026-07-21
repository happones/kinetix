<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;

class PrefixTeam extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}

class PrefixUser extends Authenticatable
{
    public $timestamps = false;

    protected $guarded = [];

    /** Mimics the starter kit's active-team accessor. */
    public function getCurrentTeamAttribute(): PrefixTeam
    {
        return new PrefixTeam(['id' => 42]);
    }
}

/**
 * With teams on, every Kinetix endpoint group must nest under `{current_team}`
 * AND carry the `kinetix.permissions.team` bridge, so policy checks on those
 * endpoints see spatie's team-scoped roles/permissions. Exports are the
 * documented exception (token-signed, queue-built download links).
 */
class TeamRoutePrefixTest extends TestCase
{
    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        // The bridge middleware resolves spatie's PermissionRegistrar.
        return [...parent::getPackageProviders($app), PermissionServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.teams', true);
    }

    public function test_kinetix_endpoint_groups_are_team_prefixed_and_carry_the_permissions_bridge(): void
    {
        $groupRepresentatives = [
            'kinetix.notifications.read-all',
            'kinetix.tables.record.store',
            'kinetix.tables.cell-update',
            'kinetix.imports.start',
            'kinetix.uploads.store',
        ];

        foreach ($groupRepresentatives as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route [{$name}] is not registered.");
            $this->assertStringStartsWith(
                '{current_team}/_kinetix/',
                $route->uri(),
                "Route [{$name}] is not nested under the team segment.",
            );
            $this->assertContains(
                'kinetix.permissions.team',
                $route->gatherMiddleware(),
                "Route [{$name}] is missing the team-permissions bridge middleware.",
            );
        }
    }

    public function test_export_routes_stay_outside_the_team_segment(): void
    {
        // Deliberate: export URLs are token-signed and the download link is
        // built inside queued jobs (no team route context available).
        foreach (['kinetix.exports.start', 'kinetix.exports.download'] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route);
            $this->assertStringStartsWith('_kinetix/exports/', $route->uri());
        }
    }

    public function test_requests_to_kinetix_endpoints_set_the_spatie_team_context(): void
    {
        $this->actingAs(new PrefixUser);

        // An invalid token 400s in the controller, but the middleware has
        // already bridged the user's current team into spatie's registrar.
        $this->postJson(
            route('kinetix.tables.record.resolve', ['current_team' => 'acme']),
            ['token' => 'not-a-valid-token'],
        )->assertStatus(400);

        $this->assertSame(42, app(PermissionRegistrar::class)->getPermissionsTeamId());
    }
}
