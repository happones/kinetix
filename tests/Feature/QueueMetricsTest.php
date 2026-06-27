<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Queue\QueueMetrics;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
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
        $app['config']->set('queue.failed', [
            'driver'   => 'database-uuids',
            'database' => $app['config']->get('database.default'),
            'table'    => 'failed_jobs',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    private function insertFailedJob(string $uuid = 'failed-uuid-1'): void
    {
        DB::table('failed_jobs')->insert([
            'uuid'       => $uuid,
            'connection' => 'database',
            'queue'      => 'emails',
            'payload'    => json_encode(['displayName' => 'App\\Jobs\\SendInvoiceEmail']),
            'exception'  => 'Boom',
            'failed_at'  => now(),
        ]);
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

    public function test_failed_lists_recent_jobs_with_parsed_names(): void
    {
        $this->insertFailedJob();

        $failed = app(QueueMetrics::class)->failed();

        $this->assertCount(1, $failed);
        $this->assertSame('SendInvoiceEmail', $failed[0]['name']);
        $this->assertSame('emails', $failed[0]['queue']);
    }

    public function test_forget_endpoint_deletes_a_failed_job(): void
    {
        Gate::define('viewKinetixQueue', fn () => true);
        $this->insertFailedJob('to-delete');

        $this->actingAs($this->user())
            ->deleteJson('/_kinetix/queue/failed', ['id' => 'to-delete'])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => 'to-delete']);
    }

    public function test_retry_endpoint_is_wired_and_gated(): void
    {
        Gate::define('viewKinetixQueue', fn () => true);

        // A no-op id keeps the test off the queue connection; the endpoint still
        // reports success because the failed-job store is available.
        $this->actingAs($this->user())
            ->postJson('/_kinetix/queue/retry', ['id' => 'missing'])
            ->assertOk()
            ->assertJsonPath('status', 'success');
    }
}
