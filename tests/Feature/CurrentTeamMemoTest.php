<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Billing\BillingManager;
use Happones\Kinetix\Features\FeatureManager;
use Happones\Kinetix\Settings\KinetixSettings;
use Happones\Kinetix\Settings\Setting;
use Happones\Kinetix\Support\KinetixTeams;
use Happones\Kinetix\Teams\TeamSwitcherManager;
use Happones\Kinetix\Tests\Concerns\CountsKinetixQueries;
use Happones\Kinetix\Tests\Concerns\ResolvesTeamRoutes;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MemoTeam extends Model
{
    protected $table = 'teams';

    public $timestamps = false;

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

class MemoTeamUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsToMany<MemoTeam, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(MemoTeam::class, 'team_user', 'user_id', 'team_id');
    }

    /**
     * @return BelongsTo<MemoTeam, $this>
     */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(MemoTeam::class, 'current_team_id');
    }
}

/**
 * The request's team is resolved once — every scoped query, settings read
 * and shared prop used to repeat the same membership JOIN — and the memo can
 * never answer for another user, another team or a membership that changed.
 */
class CurrentTeamMemoTest extends TestCase
{
    use CountsKinetixQueries;
    use ResolvesTeamRoutes;

    private MemoTeamUser $alice;

    private MemoTeam $alpha;

    private MemoTeam $beta;

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.teams', true);
        $app['config']->set('kinetix.settings.enabled', true);
        $app['config']->set('auth.providers.users.model', MemoTeamUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->unsignedInteger('current_team_id')->nullable();
        });
        Schema::create('teams', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('slug')->unique();
        });
        Schema::create('team_user', function (Blueprint $table): void {
            $table->unsignedInteger('team_id');
            $table->unsignedInteger('user_id');
        });

        (require __DIR__.'/../../database/migrations/2026_01_01_000002_create_kinetix_settings_table.php')->up();

        $this->alpha = MemoTeam::create(['slug' => 'alpha']);
        $this->beta  = MemoTeam::create(['slug' => 'beta']);
        $this->alice = $this->memberOf([$this->alpha], 'Alice');
    }

    /**
     * @param list<MemoTeam> $teams
     */
    private function memberOf(array $teams, string $name): MemoTeamUser
    {
        $user = MemoTeamUser::create(['name' => $name, 'current_team_id' => $teams[0]->getKey()]);

        foreach ($teams as $team) {
            DB::table('team_user')->insert(['team_id' => $team->getKey(), 'user_id' => $user->getKey()]);
        }

        return $user;
    }

    public function test_a_slug_segment_is_looked_up_once_per_request(): void
    {
        $this->actingAs($this->alice);
        $this->withTeamSegment('alpha');

        $queries = $this->kinetixQueriesDuring(function (): void {
            for ($i = 0; $i < 10; $i++) {
                $this->assertSame($this->alpha->getKey(), KinetixTeams::currentTeamKey());
            }

            for ($i = 0; $i < 5; $i++) {
                $this->assertTrue($this->alpha->is(KinetixTeams::currentTeam()));
            }
        });

        $this->assertCount(1, $queries, $this->describeQueries($queries));
    }

    public function test_a_bound_team_is_membership_checked_once_per_request(): void
    {
        $this->actingAs($this->alice);
        $this->withTeamSegment($this->alpha);

        $queries = $this->kinetixQueriesDuring(function (): void {
            for ($i = 0; $i < 10; $i++) {
                $this->assertSame($this->alpha->getKey(), KinetixTeams::currentTeamKey());
            }
        });

        $this->assertCount(1, $queries, $this->describeQueries($queries));
        $this->assertStringContainsString('exists', $queries[0]['sql']);
    }

    public function test_another_user_on_the_same_request_is_never_served_the_first_ones_answer(): void
    {
        $this->actingAs($this->alice);
        $this->withTeamSegment('alpha');
        $this->assertSame($this->alpha->getKey(), KinetixTeams::currentTeamKey());

        // Bob isn't in alpha: a 404, not Alice's answer.
        $this->actingAs($this->memberOf([$this->beta], 'Bob'));

        try {
            KinetixTeams::currentTeamKey();
            $this->fail('A non-member must get a 404.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        // Carol is in alpha too: her own membership query, then her own memo.
        $this->actingAs($this->memberOf([$this->beta, $this->alpha], 'Carol'));

        $queries = $this->kinetixQueriesDuring(function (): void {
            $this->assertSame($this->alpha->getKey(), KinetixTeams::currentTeamKey());
            $this->assertSame($this->alpha->getKey(), KinetixTeams::currentTeamKey());
        });

        $this->assertCount(1, $queries, $this->describeQueries($queries));
    }

    public function test_a_non_member_answer_is_never_stored(): void
    {
        $bob = $this->memberOf([$this->beta], 'Bob');
        $this->actingAs($bob);
        $this->withTeamSegment('alpha');

        foreach ([1, 2] as $attempt) {
            try {
                KinetixTeams::currentTeamKey();
                $this->fail("Attempt {$attempt}: a non-member must get a 404.");
            } catch (HttpException $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }
        }

        // Nothing was stored: once Bob joins, the same request resolves.
        DB::table('team_user')->insert(['team_id' => $this->alpha->getKey(), 'user_id' => $bob->getKey()]);

        $this->assertSame($this->alpha->getKey(), KinetixTeams::currentTeamKey());
    }

    public function test_forgetting_the_team_resolves_it_again(): void
    {
        $this->actingAs($this->alice);
        $this->withTeamSegment('alpha');
        KinetixTeams::currentTeamKey();

        // Alice leaves alpha mid-request: without forgetting, the memo would
        // still say she belongs.
        DB::table('team_user')->where('user_id', $this->alice->getKey())->delete();

        $this->assertSame($this->alpha->getKey(), KinetixTeams::currentTeamKey());

        KinetixTeams::forgetCurrentTeam();

        $this->expectException(HttpException::class);
        KinetixTeams::currentTeamKey();
    }

    public function test_settings_reads_under_a_team_cost_the_team_lookup_and_two_scope_loads(): void
    {
        Setting::query()->create(['team_id' => null, 'key' => 'site.name', 'value' => json_encode('Global')]);
        Setting::query()->create(['team_id' => $this->alpha->getKey(), 'key' => 'site.name', 'value' => json_encode('Alpha')]);

        $this->actingAs($this->alice);
        $this->withTeamSegment('alpha');

        $queries = $this->kinetixQueriesDuring(function (): void {
            for ($i = 0; $i < 10; $i++) {
                $this->assertSame('Alpha', KinetixSettings::get('site.name'));
            }
        });

        // Was 13: the team lookup ran on every read.
        $this->assertCount(3, $queries, $this->describeQueries($queries));
    }

    public function test_without_a_segment_the_current_team_relation_loads_once(): void
    {
        $this->actingAs($this->alice);
        $this->withoutTeamSegment();

        $queries = $this->kinetixQueriesDuring(function (): void {
            for ($i = 0; $i < 5; $i++) {
                $this->assertSame($this->alpha->getKey(), KinetixTeams::currentTeamKey());
            }
        });

        $this->assertCount(1, $queries, $this->describeQueries($queries));
    }

    public function test_the_resolved_team_peek_never_queries_and_waits_for_a_resolution(): void
    {
        $this->actingAs($this->alice);
        $this->withTeamSegment('alpha');

        $queries = $this->kinetixQueriesDuring(function (): void {
            $this->assertNull(KinetixTeams::resolvedCurrentTeam());
        });

        $this->assertSame([], $queries);

        KinetixTeams::currentTeam();

        $this->assertTrue($this->alpha->is(KinetixTeams::resolvedCurrentTeam()));

        // Another user's peek sees nothing of Alice's resolution.
        $this->actingAs($this->memberOf([$this->alpha], 'Dave'));
        $this->assertNull(KinetixTeams::resolvedCurrentTeam());
    }

    public function test_billing_reuses_the_resolution_only_when_both_read_the_same_segment(): void
    {
        $this->actingAs($this->alice);
        $reads = new ReflectionMethod(BillingManager::class, 'readsTheSameTeamAsKinetixTeams');

        $this->withTeamSegment('alpha', 'team');
        $this->assertTrue($reads->invoke(null, $this->alice));

        // `{team}` and `{current_team}` both present and different: Billing
        // reads `{team}`, KinetixTeams `{current_team}` — no reuse.
        request()->setRouteResolver(function (): RoutingRoute {
            $route = new RoutingRoute('GET', '{current_team}/billing/{team}', []);
            $route->bind(request());
            $route->setParameter('current_team', 'alpha');
            $route->setParameter('team', 'beta');

            return $route;
        });
        $this->assertFalse($reads->invoke(null, $this->alice));

        // Another user than the request's: no reuse either.
        $this->withTeamSegment('alpha');
        $this->assertFalse($reads->invoke(null, $this->memberOf([$this->alpha], 'Erin')));
    }

    public function test_features_resolve_for_the_urls_team_not_the_stored_one(): void
    {
        config(['kinetix.features.driver' => 'native']);
        $features = app(FeatureManager::class);
        $features->define('scope', static fn (mixed $scope): bool => $scope instanceof MemoTeam && $scope->slug === 'beta');

        // Alice's stored current team is alpha; she is a member of beta too.
        DB::table('team_user')->insert(['team_id' => $this->beta->getKey(), 'user_id' => $this->alice->getKey()]);
        $this->actingAs($this->alice);

        $this->withTeamSegment('beta');
        $this->assertTrue($features->active('scope'));

        // No team in the URL (console, a job, a plain route): the stored team.
        $this->withoutTeamSegment();
        $this->assertFalse($features->active('scope'));
    }

    public function test_a_feature_check_under_a_foreign_team_never_404s(): void
    {
        config(['kinetix.features.driver' => 'native']);
        $features = app(FeatureManager::class);
        $features->define('scope', static fn (mixed $scope): bool => $scope instanceof MemoTeamUser);

        $this->actingAs($this->alice);
        $this->withTeamSegment('beta');

        // Not one of Alice's teams: the flag resolves for Alice alone.
        $this->assertTrue($features->active('scope'));
    }

    public function test_the_team_switcher_marks_the_urls_team_as_current(): void
    {
        config(['kinetix.team_switcher.enabled' => true]);
        DB::table('team_user')->insert(['team_id' => $this->beta->getKey(), 'user_id' => $this->alice->getKey()]);
        $this->actingAs($this->alice);

        // Stored current team alpha, on beta's URL: beta is current.
        $this->withTeamSegment('beta');
        $payload = app(TeamSwitcherManager::class)->payload();

        $this->assertSame($this->beta->getKey(), $payload['current']['id'] ?? null);
        $this->assertSame([$this->beta->getKey()], array_column(array_filter($payload['teams'], static fn (array $team): bool => $team['current']), 'id'));

        // No team in the URL: the stored one.
        $this->withoutTeamSegment();
        $this->assertSame($this->alpha->getKey(), app(TeamSwitcherManager::class)->payload()['current']['id'] ?? null);
    }

    public function test_the_team_switcher_under_a_foreign_team_marks_none_and_never_404s(): void
    {
        config(['kinetix.team_switcher.enabled' => true]);
        $this->actingAs($this->alice);
        $this->withTeamSegment('beta');

        $payload = app(TeamSwitcherManager::class)->payload();

        $this->assertNull($payload['current']);
        $this->assertSame([], array_filter($payload['teams'], static fn (array $team): bool => $team['current']));
    }

    public function test_the_team_switcher_without_a_teams_relation_keeps_the_stored_team(): void
    {
        config(['kinetix.team_switcher.enabled' => true, 'kinetix.team_switcher.teams_relation' => 'memberships']);
        $this->actingAs($this->alice);
        $this->withTeamSegment('beta');

        // Legacy mode: the segment can't be resolved to a model, so the
        // stored current team is marked, as before.
        $this->assertSame($this->alpha->getKey(), app(TeamSwitcherManager::class)->payload()['current']['id'] ?? null);
    }
}
