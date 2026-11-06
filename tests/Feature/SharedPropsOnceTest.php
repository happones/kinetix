<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Support\KinetixSharedProps;
use Happones\Kinetix\Tests\TestCase;
use Happones\Kinetix\Tours\KinetixTours;
use Happones\Kinetix\Tours\TourStep;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class OnceUser extends Authenticatable
{
    protected $table = 'once_users';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * On inertia-laravel 3 the default `shared_props.once` set is sent once and
 * kept by the browser, under a key that changes with everything the prop
 * depends on — so a kept prop is never stale for another user, team, locale
 * or config, or after a Kinetix write to its module.
 */
class SharedPropsOnceTest extends TestCase
{
    private const DEFAULT_ONCE = ['kinetix_tours', 'kinetix_locale', 'kinetix_cookie_consent', 'kinetix_queue', 'kinetix_health', 'kinetix_reports_center'];

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.middleware', ['web']);
        $app['config']->set('kinetix.tours.enabled', true);
        $app['config']->set('kinetix.tours.driver', 'database');
        $app['config']->set('kinetix.accessibility.enabled', true);
    }

    /**
     * @param Application $app
     */
    protected function listsNeverOnceProps($app): void
    {
        $app['config']->set('kinetix.shared_props.once', ['kinetix_tours', 'kinetix_config', 'kinetix_notifications', 'kinetix_billing', 'kinetix_entitlements']);
    }

    /**
     * @param Router $router
     */
    protected function defineRoutes($router): void
    {
        $router->middleware('web')->get('page', static fn () => Inertia::render('Page'));
        $router->middleware('web')->get('{current_team}/page', static fn () => Inertia::render('Page'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('once_users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        (require __DIR__.'/../../database/migrations/2026_01_01_000023_create_kinetix_tour_state_table.php')->up();
        (require __DIR__.'/../../database/migrations/2026_01_01_000008_create_kinetix_accessibility_table.php')->up();

        KinetixTours::tour('posts')->url('/posts')->steps([TourStep::make('[data-tour=create]')->title('Create')]);

        $this->actingAs(OnceUser::create(['name' => 'Jane']));
    }

    /**
     * @param  array<string, string> $headers
     * @return array<string, mixed>
     */
    private function visit(string $uri = 'page', array $headers = []): array
    {
        return $this->withHeaders(['X-Inertia' => 'true', ...$headers])->get($uri)->assertOk()->json();
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array<string, string> prop => once key
     */
    private function onceKeys(array $page): array
    {
        $keys = [];

        foreach ((array) ($page['onceProps'] ?? []) as $key => $meta) {
            $keys[$meta['prop']] = $key;
        }

        return $keys;
    }

    private function requireOnce(): void
    {
        if (! KinetixSharedProps::onceSupported()) {
            $this->markTestSkipped('inertia-laravel 2 has no once props.');
        }
    }

    public function test_the_default_set_is_sent_with_fingerprinted_once_keys(): void
    {
        $this->requireOnce();

        $keys = $this->onceKeys($this->visit());

        $this->assertEqualsCanonicalizing(self::DEFAULT_ONCE, array_keys($keys));

        foreach ($keys as $prop => $key) {
            $this->assertMatchesRegularExpression('/^'.$prop.'@[0-9a-f]{16}$/', $key);
        }
    }

    public function test_props_the_browser_already_holds_are_not_sent_again(): void
    {
        $this->requireOnce();

        $first = $this->visit();
        $next  = $this->visit(headers: ['X-Inertia-Except-Once-Props' => implode(',', $this->onceKeys($first))]);

        foreach (self::DEFAULT_ONCE as $prop) {
            $this->assertArrayHasKey($prop, $first['props']);
            $this->assertArrayNotHasKey($prop, $next['props']);
        }

        // Never-kept props are always there.
        $this->assertArrayHasKey('kinetix_config', $next['props']);
        $this->assertArrayHasKey('kinetix_notifications', $next['props']);

        // A full page load (no X-Inertia) always resolves them.
        $this->withHeaders(['X-Inertia-Except-Once-Props' => implode(',', $this->onceKeys($first))])
            ->get('page')
            ->assertOk()
            ->assertSee('kinetix_tours', false);
    }

    public function test_the_key_changes_with_the_locale_the_user_the_team_the_config_and_a_refresh(): void
    {
        $this->requireOnce();

        $base = $this->onceKeys($this->visit())['kinetix_tours'];

        app()->setLocale('es');
        $this->assertNotSame($base, $locale = $this->onceKeys($this->visit())['kinetix_tours']);
        app()->setLocale('en');
        $this->assertSame($base, $this->onceKeys($this->visit())['kinetix_tours']);

        config(['kinetix.tours.skip_when_automated' => false]);
        $this->assertNotSame($base, $config = $this->onceKeys($this->visit())['kinetix_tours']);
        config(['kinetix.tours.skip_when_automated' => true]);

        // Another config section leaves this prop's key alone.
        config(['kinetix.queue.poll' => 1234]);
        $this->assertSame($base, $this->onceKeys($this->visit())['kinetix_tours']);

        config(['kinetix.teams' => true]);
        $team = $this->onceKeys($this->visit('acme/page'))['kinetix_tours'];
        $this->assertNotSame($base, $team);
        $this->assertNotSame($team, $this->onceKeys($this->visit('globex/page'))['kinetix_tours']);
        config(['kinetix.teams' => false]);

        $this->actingAs(OnceUser::create(['name' => 'John']));
        $this->assertNotSame($base, $user = $this->onceKeys($this->visit())['kinetix_tours']);

        $request = $this->app['request'];
        $request->setLaravelSession($this->app['session.store']);
        KinetixSharedProps::refresh($request);

        $this->assertNotSame($user, $refreshed = $this->onceKeys($this->visit())['kinetix_tours']);
        $this->assertCount(6, array_unique([$base, $locale, $config, $team, $user, $refreshed]));
    }

    public function test_a_successful_write_to_a_source_module_bumps_the_version(): void
    {
        $this->requireOnce();

        $before = $this->onceKeys($this->visit())['kinetix_tours'];

        $this->postJson('_kinetix/tours/posts/seen')->assertOk();

        $this->assertNotSame($before, $this->onceKeys($this->visit())['kinetix_tours']);
    }

    public function test_reads_and_failed_writes_leave_the_version_alone(): void
    {
        $this->requireOnce();

        $before = $this->onceKeys($this->visit())['kinetix_tours'];

        $this->getJson('_kinetix/accessibility')->assertOk();
        $this->postJson('_kinetix/tours/undeclared/seen')->assertNotFound();

        $this->assertSame($before, $this->onceKeys($this->visit())['kinetix_tours']);
    }

    public function test_only_the_source_modules_carry_the_refresh_middleware(): void
    {
        $middleware = static fn (string $name): array => Route::getRoutes()->getByName($name) instanceof RoutingRoute
            ? Route::getRoutes()->getByName($name)->gatherMiddleware()
            : [];

        $this->assertContains('kinetix.shared-props', $middleware('kinetix.tours.seen'));
        $this->assertContains('kinetix.shared-props', $middleware('kinetix.accessibility.update'));
        $this->assertNotContains('kinetix.shared-props', $middleware('kinetix.tables.cell-update'));
        $this->assertNotContains('kinetix.shared-props', $middleware('kinetix.notifications.read'));
    }

    #[DefineEnvironment('listsNeverOnceProps')]
    public function test_props_that_are_never_kept_are_ignored_when_listed(): void
    {
        $this->requireOnce();

        $this->assertSame(['kinetix_tours'], array_keys($this->onceKeys($this->visit())));
        $this->assertSame(['kinetix_config', 'kinetix_notifications', 'kinetix_billing', 'kinetix_entitlements'], KinetixSharedProps::ignored());
        $this->assertFalse(KinetixSharedProps::enabledFor('kinetix_billing'));
        $this->assertFalse(KinetixSharedProps::enabledFor('kinetix_entitlements'));
    }

    public function test_without_once_support_every_prop_is_sent_plainly(): void
    {
        if (KinetixSharedProps::onceSupported()) {
            $this->assertFalse(KinetixSharedProps::enabledFor('kinetix_notifications'));

            config(['kinetix.shared_props.once' => []]);
            $this->assertSame([], $this->onceKeys($this->visit()));

            return;
        }

        $page = $this->visit();

        $this->assertSame([], (array) ($page['onceProps'] ?? []));

        foreach (self::DEFAULT_ONCE as $prop) {
            $this->assertArrayHasKey($prop, $page['props']);
        }
    }
}
