<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Api\ApiLog;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ApiLogUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class ApiLogsTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.api_logs.enabled', true);
        $app['config']->set('auth.providers.users.model', ApiLogUser::class);
    }

    /**
     * @param Router $router
     */
    protected function defineRoutes($router): void
    {
        // A host API route carrying the logging middleware.
        $router->post('/api/v1/orders', fn () => response()->json(['created' => true], 201))
            ->middleware(['kinetix.api-log']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        $migration = require __DIR__.'/../../database/migrations/2026_01_01_000018_create_kinetix_api_logs_table.php';
        $migration->up();
    }

    public function test_the_middleware_logs_method_path_status_and_duration(): void
    {
        $this->postJson('/api/v1/orders', ['sku' => 'A-1'])->assertCreated();

        $log = ApiLog::query()->firstOrFail();
        $this->assertSame('POST', $log->method);
        $this->assertSame('/api/v1/orders', $log->path);
        $this->assertSame(201, $log->status);
        $this->assertNotNull($log->duration_ms);
        // Bodies are opt-in and were not enabled.
        $this->assertNull($log->request_body);
        $this->assertNull($log->response_body);
    }

    public function test_bodies_are_captured_when_enabled_and_sensitive_keys_are_redacted(): void
    {
        config()->set('kinetix.api_logs.log_request_body', true);
        config()->set('kinetix.api_logs.log_response_body', true);

        $this->postJson('/api/v1/orders', ['sku' => 'A-1', 'password' => 'hunter2'])->assertCreated();

        $log = ApiLog::query()->firstOrFail();
        $this->assertSame('A-1', $log->request_body['sku']);
        $this->assertSame('[redacted]', $log->request_body['password']);
        $this->assertSame('{"created":true}', $log->response_body);
    }

    public function test_nothing_is_logged_while_disabled(): void
    {
        config()->set('kinetix.api_logs.enabled', false);

        $this->postJson('/api/v1/orders', [])->assertCreated();

        $this->assertSame(0, ApiLog::query()->count());
    }

    public function test_the_feed_is_gated_and_filters_by_result_and_search(): void
    {
        ApiLog::create(['method' => 'GET', 'path' => '/api/v1/orders', 'status' => 200, 'token_name' => 'CI bot', 'created_at' => now()]);
        ApiLog::create(['method' => 'POST', 'path' => '/api/v1/refunds', 'status' => 422, 'created_at' => now()]);

        // Gated: without the gate the feed is denied outside local (here the
        // local-only default grants it, so define a denying gate to prove it).
        Gate::define('viewKinetixApiLogs', fn ($user = null): bool => false);
        $this->actingAs(ApiLogUser::create(['name' => 'A']))
            ->getJson('/_kinetix/api-logs')
            ->assertForbidden();

        Gate::define('viewKinetixApiLogs', fn ($user = null): bool => true);

        $all = $this->actingAs(ApiLogUser::create(['name' => 'B']))
            ->getJson('/_kinetix/api-logs')
            ->assertOk();
        $this->assertSame(2, $all->json('pagination.total'));

        $failed = $this->getJson('/_kinetix/api-logs?result=failed')->assertOk();
        $this->assertSame(1, $failed->json('pagination.total'));
        $this->assertSame('/api/v1/refunds', $failed->json('data.0.path'));

        $search = $this->getJson('/_kinetix/api-logs?search=CI bot')->assertOk();
        $this->assertSame(1, $search->json('pagination.total'));
    }

    public function test_prune_command_deletes_logs_older_than_the_retention_window(): void
    {
        ApiLog::create(['method' => 'GET', 'path' => '/old', 'status' => 200, 'created_at' => now()->subDays(40)]);
        ApiLog::create(['method' => 'GET', 'path' => '/fresh', 'status' => 200, 'created_at' => now()]);

        $this->artisan('kinetix:api-logs:prune', ['--days' => 30])->assertSuccessful();

        $this->assertSame(1, ApiLog::query()->count());
        $this->assertSame('/fresh', ApiLog::query()->firstOrFail()->path);
    }
}
