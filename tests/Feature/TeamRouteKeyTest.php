<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionServiceProvider;

class SluggedTeam extends Model
{
    protected $table = 'teams';

    public $timestamps = false;

    protected $guarded = [];

    /** The host routes teams by slug, not id. */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

class TeamRouteUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(SluggedTeam::class, 'team_members', 'user_id', 'team_id');
    }
}

/**
 * The `{current_team}` route segment carries the team's ROUTE key (slug here),
 * so data scoping must translate it to the primary key via the user's teams
 * relation — which doubles as the membership check.
 */
class TeamRouteKeyTest extends TestCase
{
    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        // Team routes carry the `kinetix.permissions.team` middleware, which
        // resolves spatie's PermissionRegistrar — its provider must be booted.
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
        $app['config']->set('kinetix.saved_views.enabled', true);
        $app['config']->set('auth.providers.users.model', TeamRouteUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
        Schema::create('teams', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('slug')->unique();
        });
        Schema::create('team_members', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('team_id');
        });
        Schema::create('kinetix_saved_views', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('view_key');
            $table->string('name');
            $table->json('state');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function test_a_slug_segment_is_stored_as_the_teams_primary_key(): void
    {
        $user = TeamRouteUser::create(['name' => 'Ada']);
        $team = SluggedTeam::create(['name' => 'Acme', 'slug' => 'acme']);
        $user->teams()->attach($team);

        $this->actingAs($user)
            ->postJson('/acme/_kinetix/saved-views', [
                'key'   => 'App\\Models\\Post',
                'name'  => 'Active',
                'state' => ['search' => 'foo'],
            ])
            ->assertCreated();

        // Scoped by the team's id — not the raw "acme" slug segment.
        $this->assertDatabaseHas('kinetix_saved_views', [
            'name'    => 'Active',
            'team_id' => $team->id,
        ]);
    }

    public function test_a_team_the_user_does_not_belong_to_is_a_404(): void
    {
        $user  = TeamRouteUser::create(['name' => 'Ada']);
        $other = SluggedTeam::create(['name' => 'Rival', 'slug' => 'rival']);

        // Member of nothing — the segment resolves through the user's teams.
        $this->actingAs($user)
            ->postJson('/rival/_kinetix/saved-views', [
                'key'   => 'App\\Models\\Post',
                'name'  => 'Sneaky',
                'state' => [],
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('kinetix_saved_views', ['name' => 'Sneaky']);
    }

    public function test_users_without_a_teams_relation_keep_the_legacy_raw_segment(): void
    {
        // SavedViewUser-style host: no teams() relation → the segment passes
        // through untouched (id-routed teams, membership on the host).
        config()->set('auth.providers.users.model', SavedViewUser::class);
        $user = SavedViewUser::create(['name' => 'Legacy']);

        $this->actingAs($user)
            ->postJson('/7/_kinetix/saved-views', [
                'key'   => 'App\\Models\\Post',
                'name'  => 'Plain',
                'state' => [],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('kinetix_saved_views', ['name' => 'Plain', 'team_id' => 7]);
    }
}
