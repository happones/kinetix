<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Confidential\ConfidentialKey;
use Happones\Kinetix\Confidential\KeyManagers\LocalKeyManager;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class ConfidentialControllerTestUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class ConfidentialControllerTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.confidential.enabled', true);
        $app['config']->set('auth.providers.users.model', ConfidentialControllerTestUser::class);
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

        $this->actingAs(ConfidentialControllerTestUser::create([
            'name'     => 'Ada',
            'password' => Hash::make('secret'),
        ]));
    }

    public function test_unlock_with_the_correct_password_succeeds(): void
    {
        $this->postJson(route('kinetix.confidential.unlock'), ['password' => 'secret'])
            ->assertOk()
            ->assertJson(['unlocked' => true]);
    }

    public function test_unlock_with_the_wrong_password_is_rejected(): void
    {
        $this->postJson(route('kinetix.confidential.unlock'), ['password' => 'wrong'])
            ->assertStatus(422)
            ->assertJson(['unlocked' => false]);
    }

    public function test_unlock_is_denied_when_the_gate_denies(): void
    {
        Gate::define('revealKinetixConfidential', fn () => false);

        $this->postJson(route('kinetix.confidential.unlock'), ['password' => 'secret'])
            ->assertForbidden();
    }

    public function test_lock_closes_the_reveal_window(): void
    {
        $this->postJson(route('kinetix.confidential.unlock'), ['password' => 'secret'])->assertOk();

        $this->postJson(route('kinetix.confidential.lock'))
            ->assertOk()
            ->assertJson(['unlocked' => false]);
    }
}
