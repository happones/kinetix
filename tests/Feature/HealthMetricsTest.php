<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Health\HealthMetrics;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class HealthUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class HealthMetricsTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.health.enabled', true);
        $app['config']->set('auth.providers.users.model', HealthUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
    }

    private function user(): HealthUser
    {
        return HealthUser::create(['name' => 'Ada']);
    }

    public function test_snapshot_is_unavailable_without_spatie_health(): void
    {
        $snapshot = app(HealthMetrics::class)->snapshot();

        $this->assertFalse($snapshot['available']);
        $this->assertNull($snapshot['status']);
        $this->assertSame([], $snapshot['checks']);
    }

    public function test_endpoint_returns_the_snapshot_when_authorized(): void
    {
        Gate::define('viewKinetixHealth', fn () => true);

        $this->actingAs($this->user())
            ->getJson('/_kinetix/health')
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('checks', []);
    }

    public function test_endpoint_is_forbidden_without_the_ability(): void
    {
        Gate::define('viewKinetixHealth', fn () => false);

        $this->actingAs($this->user())
            ->getJson('/_kinetix/health')
            ->assertForbidden();
    }
}
