<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\Concerns\BuildsAllModulesFixture;
use Happones\Kinetix\Tests\Concerns\CountsKinetixQueries;
use Happones\Kinetix\Tests\Concerns\PerfTeam;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Spatie\Permission\PermissionServiceProvider;

/**
 * The bell's JSON feed. Its poll used to be a partial reload of the page,
 * which re-ran the host page's controller (a scaffolded index: count, page,
 * eager loads) every 30 seconds per open tab.
 */
class NotificationFeedTest extends TestCase
{
    use BuildsAllModulesFixture;
    use CountsKinetixQueries;

    public static bool $hostControllerRan = false;

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

        $this->useTeamsWithNotifications($app);
    }

    /**
     * @param Router $router
     */
    protected function defineRoutes($router): void
    {
        $this->definePerfRoutes($router);

        // A host page whose controller queries, like a scaffolded index.
        $router->middleware('web')->get('{current_team}/posts', static function () {
            NotificationFeedTest::$hostControllerRan = true;

            return Inertia::render('Posts/Index', ['table' => DB::table('users')->count()]);
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::$hostControllerRan = false;
        $this->buildFixture(withPermissions: false);
    }

    private function feedUri(): string
    {
        return $this->team->slug.'/_kinetix/notifications';
    }

    public function test_the_feed_returns_what_the_shared_prop_carries(): void
    {
        $prop = $this->visitDashboard()['props']['kinetix_notifications'];

        $response = $this->actingAs($this->member)->getJson($this->feedUri())->assertOk();

        $this->assertCount(15, $prop);
        $this->assertSame($prop, $response->json('notifications'));
        $this->assertSame(['notifications'], array_keys((array) $response->json()));
    }

    public function test_the_feed_is_never_cached(): void
    {
        $cacheControl = (string) $this->actingAs($this->member)->getJson($this->feedUri())->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }

    public function test_guests_get_a_401(): void
    {
        $this->getJson($this->feedUri())->assertUnauthorized();
    }

    public function test_another_teams_notifications_are_left_out(): void
    {
        $other = PerfTeam::query()->where('slug', 'team-2')->firstOrFail();

        DB::table('notifications')->insert([
            'id'              => (string) Str::uuid(),
            'type'            => 'App\\Notifications\\Ping',
            'notifiable_type' => $this->member->getMorphClass(),
            'notifiable_id'   => $this->member->getKey(),
            'data'            => json_encode(['title' => 'Team two only', 'status' => 'info', 'team' => $other->getKey()]),
            'created_at'      => now()->addMinute(),
            'updated_at'      => now()->addMinute(),
        ]);

        $titles = array_column((array) $this->actingAs($this->member)->getJson($this->feedUri())->json('notifications'), 'title');
        $this->assertNotContains('Team two only', $titles);

        $titles = array_column((array) $this->actingAs($this->member)->getJson('team-2/_kinetix/notifications')->json('notifications'), 'title');
        $this->assertContains('Team two only', $titles);
    }

    public function test_a_poll_costs_two_kinetix_queries_and_never_runs_the_host_controller(): void
    {
        $this->actingAs($this->member);

        $queries = $this->kinetixQueriesDuring(fn () => $this->getJson($this->feedUri())->assertOk());

        $this->assertCount(2, $queries, $this->describeQueries($queries));
        $this->assertSame([], $this->otherQueries);
        $this->assertFalse(self::$hostControllerRan);

        // One ORDER BY: the relation's own `latest()` is replaced, not doubled.
        $feed = $this->queriesMatching($queries, '/from "notifications"/');
        $this->assertCount(1, $feed);
        $this->assertSame(1, substr_count(strtolower($feed[0]['sql']), 'order by'));
    }
}
