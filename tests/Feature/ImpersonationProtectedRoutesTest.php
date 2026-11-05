<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

class ProtectedRoutesUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * Kinetix's own credential endpoints are closed to an admin who is
 * impersonating someone: tokens, provider links, passwords, GDPR, sessions,
 * a member's temporary password and billing writes.
 */
class ImpersonationProtectedRoutesTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('auth.providers.users.model', ProtectedRoutesUser::class);

        foreach (['tokens', 'gdpr', 'sessions', 'credentials', 'membership', 'connected_accounts', 'billing'] as $module) {
            $app['config']->set("kinetix.{$module}.enabled", true);
        }

        $app['config']->set('kinetix.billing.auto_routes', true);
        $app['config']->set('kinetix.connected_accounts.providers', ['github' => ['label' => 'GitHub']]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('password')->nullable();
        });
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function protectedRoutes(): array
    {
        $full   = 'kinetix.impersonation.protect';
        $writes = 'kinetix.impersonation.protect:writes';

        return [
            'tokens index'            => ['kinetix.tokens.index', $writes],
            'tokens store'            => ['kinetix.tokens.store', $writes],
            'tokens destroy'          => ['kinetix.tokens.destroy', $writes],
            'provider redirect'       => ['kinetix.connected-accounts.redirect', $full],
            'provider callback'       => ['kinetix.connected-accounts.callback', $full],
            'provider password'       => ['kinetix.connected-accounts.password', $full],
            'provider unlink'         => ['kinetix.connected-accounts.destroy', $full],
            'password page'           => ['kinetix.password.change.show', $writes],
            'password change'         => ['kinetix.password.change', $writes],
            'gdpr export'             => ['kinetix.gdpr.export', $full],
            'gdpr delete'             => ['kinetix.gdpr.delete', $full],
            'sessions index'          => ['kinetix.sessions.index', $writes],
            'sessions destroy others' => ['kinetix.sessions.destroy-others', $writes],
            'member credential'       => ['kinetix.members.credential', $full],
            'billing index'           => ['billing.index', $writes],
            'billing subscribe'       => ['billing.subscribe', $writes],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function test_the_route_is_protected_while_impersonating(string $name, string $middleware): void
    {
        $route = Route::getRoutes()->getByName($name);

        $this->assertInstanceOf(RoutingRoute::class, $route, "{$name} is not registered.");
        $this->assertContains($middleware, $route->gatherMiddleware());
    }

    public function test_the_provider_list_stays_readable(): void
    {
        $route = Route::getRoutes()->getByName('kinetix.connected-accounts.index');

        $this->assertNotContains('kinetix.impersonation.protect', $route?->gatherMiddleware() ?? []);
    }

    public function test_writes_are_refused_and_reads_served_while_impersonating(): void
    {
        $user = ProtectedRoutesUser::create(['name' => 'Target']);

        $this->actingAs($user)->withSession(['kinetix_impersonator_id' => 99]);

        $this->postJson(route('kinetix.tokens.store'), ['name' => 'ci', 'abilities' => ['*']])->assertForbidden();
        $this->deleteJson(route('kinetix.sessions.destroy-others'))->assertForbidden();
        $this->postJson(route('kinetix.gdpr.delete'))->assertForbidden();
        $this->postJson(route('kinetix.password.change'), ['password' => 'x'])->assertForbidden();
        $this->getJson(route('kinetix.connected-accounts.callback', ['provider' => 'github']))->assertForbidden();
        $this->getJson(route('kinetix.connected-accounts.redirect', ['provider' => 'github']))->assertForbidden();

        $this->getJson(route('kinetix.sessions.index'))->assertOk();
    }

    public function test_the_same_writes_pass_the_guard_without_impersonation(): void
    {
        $user = ProtectedRoutesUser::create(['name' => 'Self']);

        $this->actingAs($user);

        $this->assertNotSame(403, $this->deleteJson(route('kinetix.sessions.destroy-others'))->getStatusCode());
    }
}
