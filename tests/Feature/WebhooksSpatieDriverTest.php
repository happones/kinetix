<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Happones\Kinetix\Tests\TestCase;
use Happones\Kinetix\Webhooks\KinetixWebhooks;
use Happones\Kinetix\Webhooks\WebhookDispatcher;
use Happones\Kinetix\Webhooks\WebhookEndpoint;
use Happones\Kinetix\Webhooks\WebhookLog;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Spatie\WebhookServer\CallWebhookJob;
use Spatie\WebhookServer\Events\WebhookCallSucceededEvent;
use Spatie\WebhookServer\WebhookServerServiceProvider;

class WebhooksSpatieDriverTest extends TestCase
{
    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), WebhookServerServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.webhooks.enabled', true);
        $app['config']->set('kinetix.webhooks.driver', 'spatie');
    }

    protected function setUp(): void
    {
        parent::setUp();

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

        KinetixWebhooks::events(['order.created' => 'Order created']);
    }

    private function endpoint(array $attributes = []): WebhookEndpoint
    {
        return WebhookEndpoint::create(array_merge([
            'name'   => 'E',
            'url'    => 'https://8.8.8.8/hook',
            'secret' => 'whsec_test',
            'events' => ['order.created'],
            'active' => true,
        ], $attributes));
    }

    public function test_uses_spatie_driver(): void
    {
        $this->assertTrue(app(WebhookDispatcher::class)->usesWebhookServer());
    }

    public function test_fire_dispatches_through_spatie_and_skips_ssrf(): void
    {
        Bus::fake();

        $this->endpoint(['url' => 'https://8.8.8.8/hook']);     // public → delivered
        $this->endpoint(['url' => 'http://10.0.0.1/hook']);     // private → skipped

        KinetixWebhooks::fire('order.created', ['id' => 1]);

        Bus::assertDispatchedTimes(CallWebhookJob::class, 1);
    }

    public function test_spatie_events_are_logged_to_the_kinetix_dashboard(): void
    {
        $endpoint = $this->endpoint();

        // Simulate spatie reporting a successful delivery of a Kinetix-dispatched call.
        event(new WebhookCallSucceededEvent(
            httpVerb: 'POST',
            webhookUrl: $endpoint->url,
            payload: ['event' => 'order.created', 'data' => ['id' => 1]],
            headers: [],
            meta: ['kinetix_endpoint_id' => $endpoint->id, 'kinetix_event' => 'order.created'],
            tags: [],
            attempt: 1,
            response: new Response(200, [], 'ok'),
            errorType: null,
            errorMessage: null,
            uuid: 'test-uuid',
            transferStats: null,
        ));

        $log = WebhookLog::firstOrFail();
        $this->assertSame((int) $endpoint->id, (int) $log->webhook_endpoint_id);
        $this->assertSame('order.created', $log->event);
        $this->assertTrue($log->success);
        $this->assertSame(200, $log->status_code);
    }
}
