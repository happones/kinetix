<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Activity\Activity;
use Happones\Kinetix\Activity\KinetixActivity;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class AppUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

/** The activity feed endpoint never pages more rows than `activity.max_per_page`. */
class ActivityPerPageTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.activity.enabled', true);
        $app['config']->set('kinetix.activity.driver', 'native');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('activity.view', fn () => true);

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
        });

        Schema::create('kinetix_activity', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('log_name')->nullable();
            $table->string('event')->nullable();
            $table->text('description')->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('causer_type')->nullable();
            $table->unsignedBigInteger('causer_id')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });

        Activity::query()->insert(array_fill(0, 120, ['event' => 'created', 'created_at' => now(), 'updated_at' => now()]));
    }

    public function test_a_huge_page_size_is_capped(): void
    {
        $response = $this->actingAs(AppUser::create([]))->getJson('/_kinetix/activity?per_page=100000')->assertOk();

        $response->assertJsonPath('pagination.per_page', 100);
        $this->assertCount(100, $response->json('data'));
    }

    public function test_zero_or_negative_uses_the_default(): void
    {
        $user = AppUser::create([]);

        $this->actingAs($user)->getJson('/_kinetix/activity?per_page=0')->assertJsonPath('pagination.per_page', 15);
        $this->actingAs($user)->getJson('/_kinetix/activity?per_page=-5')->assertJsonPath('pagination.per_page', 15);
    }

    public function test_the_cap_is_configurable(): void
    {
        config()->set('kinetix.activity.max_per_page', 110);

        $this->actingAs(AppUser::create([]))
            ->getJson('/_kinetix/activity?per_page=500')
            ->assertJsonPath('pagination.per_page', 110);
    }

    public function test_php_callers_choose_freely(): void
    {
        $this->assertSame(500, KinetixActivity::logger()->query(['per_page' => 500])['pagination']['per_page']);
    }
}
