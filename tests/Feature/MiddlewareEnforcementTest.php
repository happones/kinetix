<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * The rest of the suite clears `kinetix.middleware` so endpoint logic can be
 * exercised directly. That is pragmatic, but it means nothing otherwise proves
 * the endpoints are actually behind `auth` in a real app — a route registered
 * without the configured stack would pass every other test in the suite.
 *
 * This runs with the real stack and asserts it from both ends: every registered
 * route carries the configured middleware, and a guest hitting them is turned
 * away rather than served.
 */
class MiddlewareEnforcementTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Deliberately NOT cleared, unlike the base TestCase.
        $app['config']->set('kinetix.middleware', ['web', 'auth']);

        foreach (array_keys($app['config']->get('kinetix')) as $key) {
            if (is_array($app['config']->get("kinetix.{$key}")) && $app['config']->has("kinetix.{$key}.enabled")) {
                $app['config']->set("kinetix.{$key}.enabled", true);
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // `auth` redirects guests to the named login route.
        Route::get('login', fn () => 'login')->name('login');
    }

    public function test_every_kinetix_route_carries_the_configured_middleware(): void
    {
        $unprotected = [];
        $inspected   = 0;

        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $name = $route->getName();

            if ($name === null || ! str_starts_with($name, 'kinetix.')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            // The two intentional exceptions, both guest-reachable by design:
            // member activation is guarded by a signed URL, and the social-login
            // redirect/callback pair must work before the user exists.
            if ($this->isIntentionallyPublic($name, $middleware)) {
                continue;
            }

            $inspected++;

            if (! in_array('auth', $middleware, true)) {
                $unprotected[] = $name;
            }
        }

        $this->assertGreaterThan(100, $inspected, 'The route surface was not registered.');
        $this->assertSame(
            [],
            $unprotected,
            'These Kinetix routes are missing the configured auth middleware: '.implode(', ', $unprotected),
        );
    }

    public function test_a_guest_is_turned_away_from_a_representative_endpoint_per_module(): void
    {
        // One authenticated endpoint from each of the riskiest modules: reads of
        // other people's data, and writes.
        $endpoints = [
            ['post', 'kinetix.tables.cell-update'],
            ['post', 'kinetix.tables.reorder'],
            ['post', 'kinetix.exports.start'],
            ['post', 'kinetix.imports.upload'],
            ['post', 'kinetix.uploads.store'],
            ['get', 'kinetix.permissions.roles'],
            ['get', 'kinetix.sessions.index'],
            ['post', 'kinetix.gdpr.export'],
            ['get', 'kinetix.tokens.index'],
            ['get', 'kinetix.activity.index'],
        ];

        foreach ($endpoints as [$verb, $name]) {
            if (Route::getRoutes()->getByName($name) === null) {
                $this->fail("Route {$name} is not registered — update this list.");
            }

            $response = $this->{$verb}(route($name), []);

            $this->assertContains(
                $response->getStatusCode(),
                [302, 401, 403],
                "Guest reached {$name} with status {$response->getStatusCode()}",
            );
        }
    }

    /**
     * @param array<int, string> $middleware
     */
    private function isIntentionallyPublic(string $name, array $middleware): bool
    {
        // A signed URL is the credential for these, so `auth` would be wrong.
        if (in_array('signed', $middleware, true)) {
            return true;
        }

        return str_starts_with($name, 'kinetix.membership.activate')
            || str_starts_with($name, 'kinetix.membership.activation')
            || str_starts_with($name, 'kinetix.social.')
            // Auth-optional by design so the language switcher works on the login
            // screen. It only writes the session/user locale, and the value is
            // checked against the configured locales.
            || $name === 'kinetix.locale.update';
    }
}
