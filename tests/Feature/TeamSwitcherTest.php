<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Teams\TeamSwitcherManager;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;

class TeamSwitcherTeam extends Model
{
    protected $table = 'teams';

    public $timestamps = false;

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

class TeamSwitcherUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class TeamSwitcherTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.team_switcher.enabled', true);
        $app['config']->set('kinetix.team_switcher.switch_route', 'teams.switch');
        $app['config']->set('kinetix.team_switcher.create_route', 'teams.create');
        $app['config']->set('auth.providers.users.model', TeamSwitcherUser::class);
    }

    /**
     * @param Router $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('teams/{team}/switch', fn () => 'ok')->name('teams.switch');
        $router->get('teams/create', fn () => 'ok')->name('teams.create');
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
            $table->string('slug');
        });
    }

    private function actingUserWithTeams(): TeamSwitcherUser
    {
        $acme   = TeamSwitcherTeam::create(['name' => 'Acme', 'slug' => 'acme']);
        $globex = TeamSwitcherTeam::create(['name' => 'Globex', 'slug' => 'globex']);

        $user = TeamSwitcherUser::create(['name' => 'Ada']);
        $user->setRelation('teams', collect([$acme, $globex]));
        $user->setRelation('currentTeam', $acme);

        $this->actingAs($user);

        return $user;
    }

    public function test_payload_lists_teams_with_switch_urls_and_marks_the_current(): void
    {
        $this->actingUserWithTeams();

        $payload = app(TeamSwitcherManager::class)->payload();

        $this->assertTrue($payload['enabled']);
        $this->assertCount(2, $payload['teams']);
        $this->assertSame('Acme', $payload['teams'][0]['name']);
        $this->assertTrue($payload['teams'][0]['current']);
        $this->assertFalse($payload['teams'][1]['current']);
        $this->assertStringEndsWith('/teams/globex/switch', $payload['teams'][1]['url']);
        $this->assertSame('Acme', $payload['current']['name']);
        $this->assertStringEndsWith('/teams/create', $payload['createUrl']);
    }

    public function test_payload_is_empty_when_disabled(): void
    {
        config()->set('kinetix.team_switcher.enabled', false);
        $this->actingUserWithTeams();

        $payload = app(TeamSwitcherManager::class)->payload();

        $this->assertFalse($payload['enabled']);
        $this->assertSame([], $payload['teams']);
    }

    public function test_payload_is_empty_for_guests(): void
    {
        $payload = app(TeamSwitcherManager::class)->payload();

        $this->assertFalse($payload['enabled']);
        $this->assertNull($payload['current']);
    }

    public function test_switch_url_is_null_when_the_route_is_missing(): void
    {
        config()->set('kinetix.team_switcher.switch_route', 'nonexistent.route');
        $this->actingUserWithTeams();

        $payload = app(TeamSwitcherManager::class)->payload();

        $this->assertNull($payload['teams'][0]['url']);
    }
}
