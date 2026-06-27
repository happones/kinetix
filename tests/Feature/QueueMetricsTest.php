<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Queue\QueueMetrics;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class QueueUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class QueueMetricsTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.queue.enabled', true);
        $app['config']->set('kinetix.queue.queues', [
            ['connection' => null, 'queue' => 'default'],
            ['connection' => null, 'queue' => 'emails'],
        ]);
        $app['config']->set('auth.providers.users.model', QueueUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
    }

    private function user(): QueueUser
    {
        return QueueUser::create(['name' => 'Ada']);
    }

    public function test_snapshot_falls_back_without_horizon(): void
    {
        $snapshot = app(QueueMetrics::class)->snapshot();

        $this->assertFalse($snapshot['horizon']);
        $this->assertNull($snapshot['status']);
        $this->assertNull($snapshot['throughput']);
        $this->assertNull($snapshot['recentJobs']);
        $this->assertIsInt($snapshot['failedJobs']);
        $this->assertCount(2, $snapshot['queues']);
        $this->assertSame('default', $snapshot['queues'][0]['name']);
        $this->assertSame('emails', $snapshot['queues'][1]['name']);
        $this->assertIsInt($snapshot['queues'][0]['size']);
    }

    public function test_endpoint_returns_the_snapshot_when_authorized(): void
    {
        Gate::define('viewKinetixQueue', fn () => true);

        $this->actingAs($this->user())
            ->getJson('/_kinetix/queue')
            ->assertOk()
            ->assertJsonPath('horizon', false)
            ->assertJsonCount(2, 'queues');
    }

    public function test_endpoint_is_forbidden_without_the_ability(): void
    {
        Gate::define('viewKinetixQueue', fn () => false);

        $this->actingAs($this->user())
            ->getJson('/_kinetix/queue')
            ->assertForbidden();
    }
}
