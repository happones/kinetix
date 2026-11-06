<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Support\KinetixSharedProps;
use Happones\Kinetix\Support\KinetixStateReset;
use Happones\Kinetix\Tests\Concerns\BuildsAllModulesFixture;
use Happones\Kinetix\Tests\Concerns\CountsKinetixQueries;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Spatie\Permission\PermissionServiceProvider;

/**
 * What one authenticated full Inertia visit costs in Kinetix queries, pinned:
 * a new query on the common request path must be a decision, not an accident.
 *
 * Baseline before the server-performance pass (v0.276.0): 0 / 4 / 29. The
 * team lookup ran 3× (teams + notifications) and 12× (all modules); the
 * super-admin re-check reloaded the roles; the owner bypass and Billing each
 * looked the team up again.
 *
 * All modules, first visit (15): the team (1), dismissals (1), the bell (1),
 * the super-admin check in the team and without one (2), the permissions
 * prop (2: direct permissions, the role's permissions — the roles are
 * reused), tour state (1), the plan catalog (1), accessibility (1),
 * announcements (3), onboarding (1) and the team switcher's list (1). Feature
 * flags and the switcher's current team reuse the request's resolved team.
 */
class SharedPropsQueryCountTest extends TestCase
{
    use BuildsAllModulesFixture;
    use CountsKinetixQueries;

    /** The exact Kinetix queries of a first visit with every module on. */
    private const ALL_MODULES_FIRST_VISIT = 15;

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
    }

    /**
     * @param Router $router
     */
    protected function defineRoutes($router): void
    {
        $this->definePerfRoutes($router);
    }

    /**
     * @param Application $app
     */
    protected function teamsWithNotifications($app): void
    {
        $this->useTeamsWithNotifications($app);
    }

    /**
     * @param Application $app
     */
    protected function allModules($app): void
    {
        $this->useAllModules($app);
    }

    public function test_a_default_app_runs_no_kinetix_query(): void
    {
        $this->buildFixture(withPermissions: false);
        $this->app['config']->set('auth.providers.users.model', get_class($this->member));

        $queries = $this->kinetixQueriesDuring(fn () => $this->visitDashboard(teamRouted: false));

        $this->assertSame([], $queries, $this->describeQueries($queries));
    }

    #[DefineEnvironment('teamsWithNotifications')]
    public function test_teams_with_database_notifications_run_one_team_lookup_and_the_feed(): void
    {
        $this->buildFixture(withPermissions: false);

        $queries = $this->kinetixQueriesDuring(fn () => $this->visitDashboard());

        $this->assertCount(2, $queries, $this->describeQueries($queries));
        $this->assertCount(1, $this->queriesMatching($queries, '/"team_user"/'), $this->describeQueries($queries));
        $this->assertCount(1, $this->queriesMatching($queries, '/from "notifications"/'), $this->describeQueries($queries));
    }

    #[DefineEnvironment('allModules')]
    public function test_every_module_on_resolves_the_team_and_the_teamless_roles_once(): void
    {
        $this->buildFixture(withPermissions: true);

        $queries = $this->kinetixQueriesDuring(fn () => $this->visitDashboard());

        $this->assertCount(self::ALL_MODULES_FIRST_VISIT, $queries, $this->describeQueries($queries));

        // The `{current_team}` lookup by route key — once, whoever asks.
        $this->assertCount(1, $this->queriesMatching($queries, '/"team_user"\."user_id" = \? and "teams"\."slug" = \?/'), $this->describeQueries($queries));

        // The super-admin re-check without a team, once; the roles it loaded
        // for the current team are kept for the permissions prop.
        $this->assertCount(1, $this->queriesMatching($queries, '/from "roles".*"model_has_roles"\."team_id" is null/'), $this->describeQueries($queries));
        $this->assertCount(2, $this->queriesMatching($queries, '/from "roles" inner join "model_has_roles"/'), $this->describeQueries($queries));
    }

    #[DefineEnvironment('allModules')]
    public function test_a_repeat_visit_skips_the_kept_props(): void
    {
        if (! KinetixSharedProps::onceSupported()) {
            $this->markTestSkipped('inertia-laravel 2 re-sends every prop on every visit.');
        }

        $this->buildFixture(withPermissions: true);

        $first = $this->visitDashboard();

        $this->assertArrayHasKey('kinetix_tours', $first['props']);

        $keys = implode(',', array_keys((array) ($first['onceProps'] ?? [])));

        // The next request of a real app: fresh statics and a fresh user
        // object (the test reuses the one it authenticated with).
        KinetixStateReset::run(true);
        $this->member = $this->member->fresh();

        $page    = [];
        $queries = $this->kinetixQueriesDuring(function () use ($keys, &$page): void {
            $page = $this->visitDashboard(headers: ['X-Inertia-Except-Once-Props' => $keys]);
        });

        $this->assertCount(self::ALL_MODULES_FIRST_VISIT - 1, $queries, $this->describeQueries($queries));
        $this->assertSame([], $this->queriesMatching($queries, '/"kinetix_tour_state"/'));
        $this->assertArrayNotHasKey('kinetix_tours', $page['props']);
    }
}
