<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Tests\TestCase;
use Happones\Kinetix\Webhooks\DispatchWebhookJob;
use Happones\Kinetix\Webhooks\KinetixWebhooks;
use Happones\Kinetix\Webhooks\WebhookEndpoint;
use Happones\Kinetix\Webhooks\WebhookLog;
use Happones\Kinetix\Webhooks\WebhookUrlGuard;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;

class WebhookUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $guard_name = 'web';
}

class WebhooksTest extends TestCase
{
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
        $app['config']->set('kinetix.webhooks.enabled', true);
        // spatie/laravel-webhook-server is installed (dev dep), which would flip
        // the `auto` driver to spatie; pin this suite to the native delivery job.
        $app['config']->set('kinetix.webhooks.driver', 'native');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        KinetixWebhooks::events(['order.created' => 'Order created', 'order.shipped' => 'Order shipped']);

        foreach (KinetixPermissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    private function createTables(): void
    {
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        foreach (['permissions', 'roles'] as $name) {
            Schema::create($name, function ($table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
        }

        Schema::create('role_has_permissions', function ($table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });
        Schema::create('model_has_permissions', function ($table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });
        Schema::create('model_has_roles', function ($table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });

        Schema::create('kinetix_webhook_endpoints', function ($table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('name');
            $table->string('url');
            $table->string('secret');
            $table->json('events');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('kinetix_webhook_logs', function ($table) {
            $table->id();
            $table->unsignedBigInteger('webhook_endpoint_id')->index();
            $table->string('event');
            $table->json('payload')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->boolean('success')->default(false);
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->text('response')->nullable();
            $table->timestamps();
        });
    }

    private function manager(): WebhookUser
    {
        $user = WebhookUser::create(['name' => 'Manager']);
        $user->givePermissionTo('webhooks.manage');

        return $user;
    }

    private function endpoint(array $attributes = []): WebhookEndpoint
    {
        return WebhookEndpoint::create(array_merge([
            'name'   => 'Endpoint',
            'url'    => 'https://8.8.8.8/hook',
            'secret' => 'whsec_test',
            'events' => ['order.created'],
            'active' => true,
        ], $attributes));
    }

    public function test_ssrf_guard_blocks_private_and_allows_public(): void
    {
        $this->assertFalse(WebhookUrlGuard::isAllowed('http://127.0.0.1/x'));
        $this->assertFalse(WebhookUrlGuard::isAllowed('http://10.0.0.1/x'));
        $this->assertFalse(WebhookUrlGuard::isAllowed('http://169.254.169.254/latest/meta-data'));
        $this->assertFalse(WebhookUrlGuard::isAllowed('ftp://8.8.8.8'));
        $this->assertTrue(WebhookUrlGuard::isAllowed('https://8.8.8.8/hook'));

        config()->set('kinetix.webhooks.allow_private', true);
        $this->assertTrue(WebhookUrlGuard::isAllowed('http://127.0.0.1/x'));
    }

    public function test_fire_dispatches_only_to_active_subscribed_endpoints(): void
    {
        Bus::fake();

        $this->endpoint(['events' => ['order.created']]);            // match
        $this->endpoint(['events' => ['order.shipped']]);            // wrong event
        $this->endpoint(['events' => ['order.created'], 'active' => false]); // inactive

        KinetixWebhooks::fire('order.created', ['id' => 1]);

        Bus::assertDispatchedTimes(DispatchWebhookJob::class, 1);
    }

    public function test_unregistered_event_does_not_dispatch(): void
    {
        Bus::fake();
        $this->endpoint(['events' => ['ghost']]);

        KinetixWebhooks::fire('ghost', []);

        Bus::assertNotDispatched(DispatchWebhookJob::class);
    }

    public function test_job_delivers_signed_payload_and_logs_success(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $endpoint = $this->endpoint(['url' => 'https://8.8.8.8/hook', 'secret' => 'shh']);

        (new DispatchWebhookJob($endpoint->id, 'order.created', ['id' => 7]))->handle();

        Http::assertSent(function ($request) {
            $expected = hash_hmac('sha256', $request->body(), 'shh');

            return $request->hasHeader('X-Kinetix-Signature', $expected)
                && $request->hasHeader('X-Kinetix-Event', 'order.created');
        });

        $log = WebhookLog::firstOrFail();
        $this->assertTrue($log->success);
        $this->assertSame(200, $log->status_code);
    }

    public function test_job_blocks_ssrf_url_without_sending(): void
    {
        Http::fake();

        $endpoint = $this->endpoint(['url' => 'http://10.0.0.1/hook']);

        (new DispatchWebhookJob($endpoint->id, 'order.created', []))->handle();

        Http::assertNothingSent();
        $log = WebhookLog::firstOrFail();
        $this->assertFalse($log->success);
        $this->assertStringContainsString('Blocked', (string) $log->response);
    }

    public function test_endpoint_crud_is_gated_and_returns_secret_once(): void
    {
        $response = $this->actingAs($this->manager())
            ->postJson('/_kinetix/webhooks', [
                'name'   => 'My hook',
                'url'    => 'https://8.8.8.8/hook',
                'events' => ['order.created'],
            ])
            ->assertCreated()
            ->assertJsonStructure(['endpoint' => ['id', 'name', 'url', 'events'], 'secret']);

        // The secret is returned on create but never in the listing.
        $this->actingAs($this->manager())
            ->getJson('/_kinetix/webhooks')
            ->assertOk()
            ->assertJsonMissingPath('endpoints.0.secret');

        $this->actingAs(WebhookUser::create(['name' => 'Nobody']))
            ->getJson('/_kinetix/webhooks')
            ->assertForbidden();
    }

    public function test_store_rejects_ssrf_url(): void
    {
        $this->actingAs($this->manager())
            ->postJson('/_kinetix/webhooks', [
                'name'   => 'Bad',
                'url'    => 'http://169.254.169.254/',
                'events' => ['order.created'],
            ])
            ->assertStatus(422);
    }
}
