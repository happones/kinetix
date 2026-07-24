<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;

class PrefixTeam extends Model
{
    protected $table = 'prefix_teams';

    public $timestamps = false;

    protected $guarded = [];

    /** The host routes teams by slug. */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

class PrefixUser extends Authenticatable
{
    protected $table = 'prefix_users';

    public $timestamps = false;

    protected $guarded = [];

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(PrefixTeam::class, 'prefix_team_members', 'user_id', 'team_id');
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

    public function test_requests_to_kinetix_endpoints_set_the_spatie_team_context_from_the_url(): void
    {
        $user = $this->memberOfAcme();

        $this->actingAs($user);

        // An invalid token 400s in the controller, but the middleware has
        // already resolved the URL's team segment (via the user's membership)
        // into spatie's registrar.
        $this->postJson(
            route('kinetix.tables.record.resolve', ['current_team' => 'acme']),
            ['token' => 'not-a-valid-token'],
        )->assertStatus(400);

        $this->assertSame(
            PrefixTeam::where('slug', 'acme')->first()->getKey(),
            app(PermissionRegistrar::class)->getPermissionsTeamId(),
        );
    }

    public function test_a_team_segment_the_user_does_not_belong_to_is_rejected(): void
    {
        $this->actingAs($this->memberOfAcme());
        PrefixTeam::create(['name' => 'Other', 'slug' => 'other']);

        // Membership doubles as the guard: a foreign team segment 404s before
        // any permission context is set.
        $this->postJson(
            route('kinetix.tables.record.resolve', ['current_team' => 'other']),
            ['token' => 'not-a-valid-token'],
        )->assertNotFound();
    }

    /**
     * A user who belongs to the `acme` team (tables created on demand).
     */
    private function memberOfAcme(): PrefixUser
    {
        if (! Schema::hasTable('prefix_teams')) {
            Schema::create('prefix_teams', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name');
                $table->string('slug')->unique();
            });

            Schema::create('prefix_users', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name')->nullable();
            });

            Schema::create('prefix_team_members', function (Blueprint $table) {
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('team_id');
            });
        }

        $team = PrefixTeam::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = PrefixUser::create(['name' => 'Member']);
        $user->teams()->attach($team->getKey());

        return $user;
    }
}
