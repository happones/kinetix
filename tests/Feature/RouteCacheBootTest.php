<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\KinetixServiceProvider;
use Happones\Kinetix\Permissions\PermissionRegistry;
use Happones\Kinetix\Support\KinetixThrottle;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Facades\Route;

/**
 * With `php artisan route:cache` the app loads Kinetix's ~200 routes from the
 * cache, so the provider must not build them again on every request — but
 * everything else it registers at boot (Gates, abilities, listeners,
 * broadcast channels, middleware aliases) is not in that cache and must stay.
 */
class RouteCacheBootTest extends TestCase
{
    private ?string $cacheFile = null;

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        foreach (array_keys((array) $app['config']->get('kinetix')) as $key) {
            if (is_array($app['config']->get("kinetix.{$key}")) && $app['config']->has("kinetix.{$key}.enabled")) {
                $app['config']->set("kinetix.{$key}.enabled", true);
            }
        }

        $app['config']->set('kinetix.teams', true);
        $app['config']->set('kinetix.channels.broadcast', true);
        $app['config']->set('broadcasting.default', 'null');
        $app['config']->set('kinetix.billing.auto_routes', true);
        $app['config']->set('kinetix.tours.driver', 'database');
    }

    protected function tearDown(): void
    {
        if ($this->cacheFile !== null) {
            @unlink($this->cacheFile);
        }

        unset($_ENV['APP_ROUTES_CACHE'], $_SERVER['APP_ROUTES_CACHE']);
        putenv('APP_ROUTES_CACHE');

        parent::tearDown();
    }

    public function test_a_cached_boot_adds_no_route_but_keeps_everything_else(): void
    {
        $uncached = $this->bootFresh(cached: false);
        $cached   = $this->bootFresh(cached: true);

        $this->assertGreaterThan(100, $uncached['routes']);
        $this->assertSame(0, $cached['routes']);

        foreach (['abilities', 'channels', 'aliases', 'features', 'listeners', 'wildcards', 'limiters'] as $part) {
            $this->assertSame($uncached[$part], $cached[$part], "The cached boot lost {$part}.");
        }

        $this->assertContains('viewKinetixQueue', $cached['abilities']);
        $this->assertContains('viewKinetixReportsCenter', $cached['abilities']);
        $this->assertContains('roles', $cached['features']);
        $this->assertContains('kinetix.channels', $cached['channels']);
        $this->assertContains('kinetix.inbox.{channel}', $cached['channels']);
        $this->assertContains('kinetix.shared-props', $cached['aliases']);
        $this->assertContains('kinetix.permissions.team', $cached['aliases']);
        $this->assertContains('kinetix.plan', $cached['aliases']);

        // A cached route naming a missing limiter would answer every request 429.
        $this->assertSame(array_keys(KinetixThrottle::LIMITERS), $cached['limiters']);
        $this->assertContains('eloquent.created: *', $cached['wildcards']);
        $this->assertContains('eloquent.replicating: *', $cached['wildcards']);
    }

    /**
     * Boot a new provider on a fresh router, Gate, broadcaster, event
     * dispatcher and permission registry, and take stock of what it
     * registered.
     *
     * @return array{routes: int, abilities: list<string>, channels: list<string>, aliases: list<string>, features: list<string>, listeners: array<string, int>, wildcards: list<string>, limiters: list<string>}
     */
    private function bootFresh(bool $cached): array
    {
        $this->useCachedRoutes($cached);

        $events = new Dispatcher($this->app);
        $this->app->instance('events', $events);
        Event::clearResolvedInstance('events');

        $router = new Router($events, $this->app);
        $this->app->instance('router', $router);
        Route::clearResolvedInstance('router');

        $gate = new AccessGate($this->app, static fn () => null);
        $this->app->instance(GateContract::class, $gate);
        Gate::clearResolvedInstance(GateContract::class);

        $broadcast = new BroadcastManager($this->app);
        $this->app->instance(BroadcastManager::class, $broadcast);
        $this->app->instance(BroadcastFactory::class, $broadcast);
        Broadcast::clearResolvedInstance(BroadcastFactory::class);

        $this->app->forgetInstance(PermissionRegistry::class);

        $limiter = new RateLimiter($this->app->make('cache')->driver());
        $this->app->instance(RateLimiter::class, $limiter);
        RateLimiterFacade::clearResolvedInstance(RateLimiter::class);

        (new KinetixServiceProvider($this->app))->boot();

        $listeners = [];

        foreach ($events->getRawListeners() as $event => $registered) {
            $listeners[$event] = count($registered);
        }

        ksort($listeners);

        $abilities = array_keys($gate->abilities());
        sort($abilities);

        $channels = array_keys($broadcast->driver()->getChannels()->all());
        sort($channels);

        $aliases = array_keys($router->getMiddleware());
        sort($aliases);

        $features = array_keys($this->app->make(PermissionRegistry::class)->features());
        sort($features);

        return [
            'routes'    => count($router->getRoutes()->getRoutes()),
            'abilities' => $abilities,
            'channels'  => $channels,
            'aliases'   => $aliases,
            'features'  => $features,
            'listeners' => $listeners,
            'wildcards' => array_keys((array) (new \ReflectionProperty($events, 'wildcards'))->getValue($events)),
            'limiters'  => array_values(array_filter(
                array_keys(KinetixThrottle::LIMITERS),
                static fn (string $name): bool => $limiter->limiter($name) !== null,
            )),
        ];
    }

    /**
     * Point `APP_ROUTES_CACHE` at an existing file (or nowhere), the way
     * `route:cache` leaves the app.
     */
    private function useCachedRoutes(bool $cached): void
    {
        if ($cached && $this->cacheFile === null) {
            $this->cacheFile = (string) tempnam(sys_get_temp_dir(), 'kinetix-routes');
        }

        if (! $cached && $this->cacheFile !== null) {
            @unlink($this->cacheFile);
            $this->cacheFile = null;
        }

        $path = $cached ? $this->cacheFile : sys_get_temp_dir().'/kinetix-no-routes-cache-'.getmypid().'.php';

        $_ENV['APP_ROUTES_CACHE']    = $path;
        $_SERVER['APP_ROUTES_CACHE'] = $path;
        putenv('APP_ROUTES_CACHE='.$path);

        $this->app->forgetInstance('routes.cached');
    }
}
