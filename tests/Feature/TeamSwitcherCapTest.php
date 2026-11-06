<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Teams\TeamSwitcherManager;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TscTeam extends Model
{
    protected $table = 'tsc_teams';

    public $timestamps = false;

    protected $guarded = [];
}

class TscUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    /** @return BelongsToMany<TscTeam, $this> */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(TscTeam::class, 'tsc_team_user', 'user_id', 'team_id');
    }

    /** @return BelongsTo<TscTeam, $this> */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(TscTeam::class, 'current_team_id');
    }
}

/**
 * The team switcher shares at most `max_teams` teams on every page (the
 * current team always among them) and searches the rest, over the user's
 * own teams only.
 */
class TeamSwitcherCapTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.team_switcher.enabled', true);
        $app['config']->set('auth.providers.users.model', TscUser::class);
    }

    /**
     * @param Router $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('teams/{team}/switch', fn () => 'ok')->name('teams.switch');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('current_team_id')->nullable();
        });
        Schema::create('tsc_teams', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('label')->nullable();
        });
        Schema::create('tsc_team_user', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('team_id');
        });
    }

    public function test_a_member_of_many_teams_gets_the_capped_list_the_total_and_a_search_url(): void
    {
        $user = $this->memberOf(25);

        DB::enableQueryLog();
        $payload = app(TeamSwitcherManager::class)->payload();
        $queries = array_filter(DB::getQueryLog(), static fn (array $q): bool => str_contains($q['query'], 'tsc_team_user'));
        DB::disableQueryLog();

        $this->assertCount(20, $payload['teams']);
        $this->assertTrue($payload['truncated']);
        $this->assertSame(25, $payload['total']);
        $this->assertStringEndsWith('/_kinetix/team-switcher/teams', (string) $payload['searchUrl']);
        $this->assertCount(2, $queries, 'one capped list and one count');
        $this->assertSame((int) $user->current_team_id, $payload['teams'][0]['id']);
    }

    public function test_the_current_team_beyond_the_cut_is_listed_once(): void
    {
        $user = $this->memberOf(25);
        $last = TscTeam::query()->orderByDesc('id')->firstOrFail();
        $user->forceFill(['current_team_id' => $last->id])->save();

        $payload = app(TeamSwitcherManager::class)->payload();

        $this->assertCount(21, $payload['teams']);
        $current = array_values(array_filter($payload['teams'], static fn (array $team): bool => $team['current']));
        $this->assertCount(1, $current);
        $this->assertSame($last->id, $current[0]['id']);
    }

    public function test_a_member_of_few_teams_gets_them_all_in_one_query(): void
    {
        $this->memberOf(5);

        DB::enableQueryLog();
        $payload = app(TeamSwitcherManager::class)->payload();
        $queries = array_filter(DB::getQueryLog(), static fn (array $q): bool => str_contains($q['query'], 'tsc_team_user'));
        DB::disableQueryLog();

        $this->assertCount(5, $payload['teams']);
        $this->assertFalse($payload['truncated']);
        $this->assertSame(5, $payload['total']);
        $this->assertNull($payload['searchUrl']);
        $this->assertCount(1, $queries);
    }

    public function test_no_cap_lists_every_team(): void
    {
        config()->set('kinetix.team_switcher.max_teams', null);
        $this->memberOf(25);

        $payload = app(TeamSwitcherManager::class)->payload();

        $this->assertCount(25, $payload['teams']);
        $this->assertFalse($payload['truncated']);
    }

    public function test_the_search_never_returns_a_team_the_user_is_not_in(): void
    {
        $user = $this->memberOf(25);
        TscTeam::create(['name' => 'Secret Rival Corp']);

        $this->actingAs($user)
            ->getJson('/_kinetix/team-switcher/teams?q=Rival')
            ->assertOk()
            ->assertExactJson(['teams' => [], 'truncated' => false])
            ->assertHeader('Cache-Control', 'no-store, private');

        $found = $this->actingAs($user)->getJson('/_kinetix/team-switcher/teams?q=team%2024')->assertOk()->json('teams');
        $this->assertSame(['Team 24'], array_column($found, 'name'));
        $this->assertFalse($found[0]['current']);
        $this->assertArrayHasKey('url', $found[0]);
    }

    public function test_like_wildcards_match_literally(): void
    {
        $user = $this->memberOf(3);
        $odd  = TscTeam::create(['name' => '100% Pure']);
        $user->teams()->attach($odd->id);

        $found = $this->actingAs($user)->getJson('/_kinetix/team-switcher/teams?q=%25')->assertOk()->json('teams');

        $this->assertSame(['100% Pure'], array_column($found, 'name'));
        $this->assertSame([], $this->actingAs($user)->getJson('/_kinetix/team-switcher/teams?q=_')->json('teams'));
    }

    public function test_the_search_column_is_configurable(): void
    {
        config()->set('kinetix.team_switcher.search_column', 'label');
        $user = $this->memberOf(2);
        TscTeam::query()->where('name', 'Team 1')->update(['label' => 'Northwind']);

        $found = $this->actingAs($user)->getJson('/_kinetix/team-switcher/teams?q=north')->json('teams');

        $this->assertSame(['Team 1'], array_column($found, 'name'));
    }

    public function test_the_search_is_validated_and_throttled(): void
    {
        $user = $this->memberOf(2);

        $this->actingAs($user)->getJson('/_kinetix/team-switcher/teams')->assertStatus(422);
        $this->actingAs($user)->getJson('/_kinetix/team-switcher/teams?q='.str_repeat('a', 101))->assertStatus(422);

        // Every attempt counts, the refused ones included.
        config()->set('kinetix.team_switcher.search_throttle', '3,1');

        $this->actingAs($user)->getJson('/_kinetix/team-switcher/teams?q=a')->assertOk();
        $this->actingAs($user)->getJson('/_kinetix/team-switcher/teams?q=a')->assertStatus(429)->assertJsonPath('code', 'throttled');
    }

    public function test_guests_cannot_search(): void
    {
        $this->getJson('/_kinetix/team-switcher/teams?q=a')->assertStatus(401);
    }

    /** A user in `$count` teams ("Team 1"…), current on the first. */
    private function memberOf(int $count): TscUser
    {
        $user = TscUser::create([]);

        for ($index = 1; $index <= $count; $index++) {
            $team = TscTeam::create(['name' => "Team {$index}"]);
            $user->teams()->attach($team->id);

            if ($index === 1) {
                $user->forceFill(['current_team_id' => $team->id])->save();
            }
        }

        $this->actingAs($user);

        return $user;
    }
}
