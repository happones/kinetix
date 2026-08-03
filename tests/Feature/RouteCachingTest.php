<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * `php artisan route:cache` is a standard production deploy step, and it fails
 * outright on any route whose action is a Closure. A package that registers even
 * one closure route makes every consuming app un-deployable, so this guards the
 * whole surface rather than individual endpoints.
 */
class RouteCachingTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Enable every optional module so its routes are actually registered;
        // a closure hiding behind a disabled feature flag would otherwise pass.
        foreach (array_keys($app['config']->get('kinetix')) as $key) {
            if (is_array($app['config']->get("kinetix.{$key}")) && $app['config']->has("kinetix.{$key}.enabled")) {
                $app['config']->set("kinetix.{$key}.enabled", true);
            }
        }
    }

    public function test_no_kinetix_route_uses_a_closure_action(): void
    {
        $closureRoutes = [];
        $inspected     = 0;

        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $name = $route->getName();

            if ($name === null || ! str_starts_with($name, 'kinetix.')) {
                continue;
            }

            $inspected++;

            if ($route->getActionName() === 'Closure') {
                $closureRoutes[] = $name;
            }
        }

        // Guard against the assertion passing vacuously if the modules ever stop
        // registering their routes in this environment.
        $this->assertGreaterThan(100, $inspected);

        $this->assertSame(
            [],
            $closureRoutes,
            'These Kinetix routes use Closure actions and break `php artisan route:cache`: '
                .implode(', ', $closureRoutes),
        );
    }

    public function test_the_whole_route_collection_can_be_serialized_for_caching(): void
    {
        $routes = Route::getRoutes();
        $routes->refreshNameLookups();
        $routes->refreshActionLookups();

        foreach ($routes as $route) {
            $route->prepareForSerialization();
        }

        $this->assertNotEmpty(serialize($routes->getRoutes()));
    }
}
