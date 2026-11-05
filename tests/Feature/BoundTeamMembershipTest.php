<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Support\KinetixTeams;
use Happones\Kinetix\Tags\Tag;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionServiceProvider;

class BoundTeam extends Model
{
    protected $table = 'teams';

    public $timestamps = false;

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

class BoundTeamUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(BoundTeam::class, 'team_members', 'user_id', 'team_id');
    }
}

class BoundTeamTeamlessUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * A host that binds `{current_team}` to its team model itself (`Route::bind`)
 * gets the same membership check as the scalar segment: someone else's team
 * is a 404, not their data.
 */
class BoundTeamMembershipTest extends TestCase
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
        $app['config']->set('kinetix.teams', true);
        $app['config']->set('kinetix.tags.enabled', true);
        $app['config']->set('auth.providers.users.model', BoundTeamUser::class);
        // The host's binding only applies through SubstituteBindings.
        $app['config']->set('kinetix.middleware', [SubstituteBindings::class]);
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
            $table->string('slug')->unique();
        });
        Schema::create('team_members', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('team_id');
        });
        Schema::create('kinetix_tags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
            $table->unique(['team_id', 'slug']);
        });

        Route::bind('current_team', fn (string $value): BoundTeam => BoundTeam::query()->where('slug', $value)->firstOrFail());
    }

    public function test_a_bound_team_the_user_is_not_in_is_a_404(): void
    {
        $alpha = BoundTeam::create(['slug' => 'alpha']);
        $beta  = BoundTeam::create(['slug' => 'beta']);
        Tag::create(['team_id' => $beta->id, 'name' => 'Beta secret', 'slug' => 'beta-secret']);

        $user = BoundTeamUser::create(['name' => 'Ada']);
        $user->teams()->attach($alpha);

        $this->actingAs($user)->getJson('/beta/_kinetix/tags/suggest?q=beta')->assertNotFound();
    }

    public function test_a_bound_team_the_user_is_in_resolves(): void
    {
        $beta = BoundTeam::create(['slug' => 'beta']);
        Tag::create(['team_id' => $beta->id, 'name' => 'Beta tag', 'slug' => 'beta-tag']);

        $user = BoundTeamUser::create(['name' => 'Ada']);
        $user->teams()->attach($beta);

        $this->actingAs($user)
            ->getJson('/beta/_kinetix/tags/suggest?q=beta')
            ->assertOk()
            ->assertJsonPath('tags', ['Beta tag']);
    }

    public function test_without_a_teams_relation_the_bound_key_is_returned(): void
    {
        $team = BoundTeam::create(['slug' => 'gamma']);
        $user = BoundTeamTeamlessUser::create(['name' => 'Ada']);

        $request = Request::create('/gamma/x');
        $route   = (new RoutingRoute('GET', '{current_team}/x', fn () => null))->bind($request);
        $route->setParameter('current_team', $team);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $user);

        $this->assertSame($team->id, KinetixTeams::currentTeamKey($request));
    }
}
