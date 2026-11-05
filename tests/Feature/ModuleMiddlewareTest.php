<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Closure;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class ModuleMiddlewareUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

/** Stands in for a host's plan / feature-flag gate. */
class DenyModuleMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        abort(403, 'Not on your plan.');
    }
}

/**
 * `kinetix.module_middleware.{module}` appends the host's own middleware to
 * that module's authenticated routes — the server-side hook for plans and
 * feature flags.
 */
class ModuleMiddlewareTest extends TestCase
{
    /**
     * The config module names every authenticated route group answers to.
     *
     * @var list<string>
     */
    private const MODULES = [
        'permissions', 'credentials', 'membership', 'settings', 'activity', 'impersonation', 'tours', 'help',
        'spotlight', 'webhooks', 'channels', 'inbox', 'flows', 'pdf', 'api_logs', 'tokens', 'onboarding',
        'wizards', 'gdpr', 'accessibility', 'connected_accounts', 'sessions', 'comments', 'tags',
        'notification_preferences', 'saved_views', 'announcements', 'queue', 'mail_templates', 'health',
        'reports_center', 'confidential', 'dismissals', 'notifications', 'tables', 'forms', 'uploads',
        'imports', 'exports',
    ];

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.middleware', ['web', 'auth']);
        $app['config']->set('auth.providers.users.model', ModuleMiddlewareUser::class);

        foreach (array_keys($app['config']->get('kinetix')) as $key) {
            if (is_array($app['config']->get("kinetix.{$key}")) && $app['config']->has("kinetix.{$key}.enabled")) {
                $app['config']->set("kinetix.{$key}.enabled", true);
            }
        }

        $gates = [];

        foreach (self::MODULES as $module) {
            $gates[$module] = ["probe:{$module}"];
        }

        $gates['webhooks'] = ['kinetix-test-deny'];

        $app['config']->set('kinetix.module_middleware', $gates);
        $app['router']->aliasMiddleware('kinetix-test-deny', DenyModuleMiddleware::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($table) {
            $table->increments('id');
        });

        Route::get('login', fn () => 'login')->name('login');
    }

    public function test_a_module_gate_is_enforced_on_its_routes(): void
    {
        $this->actingAs(ModuleMiddlewareUser::create([]))
            ->getJson(route('kinetix.webhooks.index'))
            ->assertForbidden()
            ->assertJsonPath('message', 'Not on your plan.');
    }

    public function test_every_authenticated_route_carries_exactly_its_modules_entry(): void
    {
        $missing  = [];
        $examined = 0;

        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $name       = (string) $route->getName();
            $middleware = $route->gatherMiddleware();

            if (! str_starts_with($name, 'kinetix.') || ! in_array('auth', $middleware, true)) {
                continue;
            }

            $examined++;

            $gates = array_values(array_filter(
                $middleware,
                static fn (mixed $entry): bool => is_string($entry) && (str_starts_with($entry, 'probe:') || $entry === 'kinetix-test-deny'),
            ));

            if (count($gates) !== 1) {
                $missing[] = $name.' ['.implode(',', $gates).']';
            }
        }

        $this->assertGreaterThan(100, $examined);
        $this->assertSame([], $missing, 'Routes without exactly one module gate: '.implode(', ', $missing));

        // Spot-check the module each group answers to.
        $gate = static fn (string $name): array => array_values(array_filter(
            Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [],
            static fn (mixed $entry): bool => is_string($entry) && str_starts_with($entry, 'probe:'),
        ));

        $this->assertSame(['probe:tables'], $gate('kinetix.tables.cell-update'));
        $this->assertSame(['probe:forms'], $gate('kinetix.forms.recompute'));
        $this->assertSame(['probe:uploads'], $gate('kinetix.uploads.store'));
        $this->assertSame(['probe:exports'], $gate('kinetix.exports.start'));
        $this->assertSame(['probe:flows'], $gate('kinetix.flows.simulate'));
        $this->assertSame(['probe:tokens'], $gate('kinetix.tokens.store'));
    }

    public function test_guest_and_signed_routes_carry_no_module_gate(): void
    {
        foreach (['kinetix.membership.activate.show', 'kinetix.channels.webhook'] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, $name);
            $this->assertSame([], array_filter(
                $route->gatherMiddleware(),
                static fn (mixed $entry): bool => is_string($entry) && str_starts_with($entry, 'probe:'),
            ), $name);
        }
    }
}
