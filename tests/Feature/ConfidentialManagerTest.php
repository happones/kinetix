<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Confidential\ConfidentialKey;
use Happones\Kinetix\Confidential\ConfidentialManager;
use Happones\Kinetix\Confidential\KeyManagers\LocalKeyManager;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class ConfidentialManagerTestUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class ConfidentialManagerTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.confidential.enabled', true);
        $app['config']->set('kinetix.activity.driver', 'native');
        $app['config']->set('auth.providers.users.model', ConfidentialManagerTestUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('revealKinetixConfidential', fn () => true);

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('password')->nullable();
        });

        Schema::create('kinetix_confidential_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key_id')->unique();
            $table->string('driver');
            $table->text('wrapped_key');
            $table->boolean('is_current')->default(false);
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });

        $generated = (new LocalKeyManager)->generateDataKey();

        ConfidentialKey::create([
            'key_id'      => 'test-key',
            'driver'      => 'local',
            'wrapped_key' => $generated['wrapped'],
            'is_current'  => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function user(string $password = 'correct-password'): ConfidentialManagerTestUser
    {
        return ConfidentialManagerTestUser::create([
            'name'     => 'Ada',
            'password' => Hash::make($password),
        ]);
    }

    public function test_is_unlocked_is_false_by_default(): void
    {
        $this->actingAs($this->user());

        $this->assertFalse(app(ConfidentialManager::class)->isUnlocked());
    }

    public function test_unlock_with_the_correct_password_opens_the_reveal_window(): void
    {
        $this->actingAs($this->user('secret'));
        $manager = app(ConfidentialManager::class);

        $this->assertTrue($manager->unlock('secret'));
        $this->assertTrue($manager->isUnlocked());
    }

    public function test_unlock_with_the_wrong_password_fails(): void
    {
        $this->actingAs($this->user('secret'));
        $manager = app(ConfidentialManager::class);

        $this->assertFalse($manager->unlock('wrong'));
        $this->assertFalse($manager->isUnlocked());
    }

    public function test_unlock_is_denied_when_the_gate_denies(): void
    {
        Gate::define('revealKinetixConfidential', fn () => false);

        $this->actingAs($this->user('secret'));

        $this->assertFalse(app(ConfidentialManager::class)->unlock('secret'));
    }

    public function test_the_reveal_window_expires_after_the_configured_ttl(): void
    {
        config()->set('kinetix.confidential.reveal_ttl_minutes', 5);
        $this->actingAs($this->user('secret'));
        $manager = app(ConfidentialManager::class);

        $this->assertTrue($manager->unlock('secret'));
        $this->assertTrue($manager->isUnlocked());

        Carbon::setTestNow(now()->addMinutes(6));

        $this->assertFalse($manager->isUnlocked());
    }

    public function test_lock_closes_the_window_before_its_ttl_expires(): void
    {
        $this->actingAs($this->user('secret'));
        $manager = app(ConfidentialManager::class);

        $manager->unlock('secret');
        $this->assertTrue($manager->isUnlocked());

        $manager->lock();
        $this->assertFalse($manager->isUnlocked());
    }

    public function test_revealed_override_grants_access_without_touching_the_session(): void
    {
        $manager = app(ConfidentialManager::class);

        $this->assertFalse($manager->isUnlocked());

        $result = $manager->revealed(fn () => $manager->isUnlocked());

        $this->assertTrue($result);
        $this->assertFalse($manager->isUnlocked());
    }

    public function test_unlock_and_lock_each_write_an_activity_log_entry(): void
    {
        config()->set('kinetix.activity.enabled', true);

        Schema::create('kinetix_activity', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable();
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

        $this->actingAs($this->user('secret'));
        $manager = app(ConfidentialManager::class);

        $manager->unlock('secret');
        $manager->lock();

        $this->assertSame(1, DB::table('kinetix_activity')->where('event', 'confidential.unlocked')->count());
        $this->assertSame(1, DB::table('kinetix_activity')->where('event', 'confidential.locked')->count());
    }
}
