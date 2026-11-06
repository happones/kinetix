<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Confidential\ConfidentialKey;
use Happones\Kinetix\Confidential\KeyManagers\LocalKeyManager;
use Happones\Kinetix\Support\KinetixThrottle;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class PctUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * Every endpoint that checks the user's current password shares ONE
 * per-user budget, so a hijacked session can't guess the password online,
 * not even by rotating endpoints.
 */
class PasswordConfirmationThrottleTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', PctUser::class);
        $app['config']->set('kinetix.confidential.enabled', true);
        $app['config']->set('kinetix.gdpr.enabled', true);
        $app['config']->set('kinetix.gdpr.require_password', true);
        $app['config']->set('kinetix.sessions.enabled', true);
        $app['config']->set('session.driver', 'database');
        $app['config']->set('session.table', 'sessions');
        $app['config']->set('kinetix.connected_accounts.enabled', true);
        $app['config']->set('kinetix.connected_accounts.providers', ['github' => ['enabled' => true]]);
        $app['config']->set('kinetix.credentials.enabled', true);
        $app['config']->set('kinetix.credentials.user_model', PctUser::class);
    }

    /**
     * @param Application $app
     */
    protected function withClientProfile($app): void
    {
        $app['config']->set('auth.guards.client', ['driver' => 'session', 'provider' => 'users']);
        $app['config']->set('kinetix.credentials.profiles', ['client' => ['guard' => 'client']]);
    }

    /**
     * @param Application $app
     */
    protected function withMalformedLimit($app): void
    {
        $app['config']->set('kinetix.password_confirmation.throttle', 'abc');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('revealKinetixConfidential', fn () => true);

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamp('password_changed_at')->nullable();
            $table->boolean('must_change_password')->default(false);
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('kinetix_connected_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('provider');
            $table->string('provider_id');
            $table->timestamps();
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

        ConfidentialKey::create([
            'key_id'      => 'test-key',
            'driver'      => 'local',
            'wrapped_key' => (new LocalKeyManager)->generateDataKey()['wrapped'],
            'is_current'  => true,
        ]);

        Route::get('login', fn () => 'login')->name('login');
    }

    public function test_the_sixth_attempt_is_throttled_even_with_the_right_password(): void
    {
        $user = $this->user();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->actingAs($user)->postJson(route('kinetix.confidential.unlock'), ['password' => 'wrong'])->assertStatus(422);
        }

        $response = $this->actingAs($user)->postJson(route('kinetix.confidential.unlock'), ['password' => 'secret']);

        $this->assertThrottled($response);
        $response->assertJsonPath('errors.password.0', $response->json('message'));
        $this->assertStringContainsString((string) $response->json('retry_after'), (string) $response->json('message'));
    }

    public function test_the_message_is_translated(): void
    {
        $user = $this->user();
        App::setLocale('es');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->actingAs($user)->postJson(route('kinetix.confidential.unlock'), ['password' => 'wrong']);
        }

        $response = $this->actingAs($user)->postJson(route('kinetix.confidential.unlock'), ['password' => 'wrong']);

        $this->assertThrottled($response);
        $this->assertStringStartsWith('Demasiados intentos.', (string) $response->json('message'));
    }

    public function test_rotating_endpoints_shares_one_budget(): void
    {
        $user = $this->user();

        $this->actingAs($user)->postJson('/_kinetix/gdpr/delete', ['password' => 'wrong'])->assertStatus(422);
        $this->actingAs($user)->postJson('/_kinetix/gdpr/delete', ['password' => 'wrong'])->assertStatus(422);
        $this->actingAs($user)->deleteJson('/_kinetix/sessions/others', ['password' => 'wrong'])->assertStatus(422);
        $this->actingAs($user)->deleteJson('/_kinetix/sessions/others', ['password' => 'wrong'])->assertStatus(422);
        $this->actingAs($user)->postJson('/_kinetix/connected-accounts/password', [
            'current_password'      => 'wrong',
            'password'              => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(422);

        $this->assertThrottled($this->actingAs($user)->postJson(route('kinetix.confidential.unlock'), ['password' => 'secret']));
        $this->assertThrottled($this->actingAs($user)->postJson('/_kinetix/gdpr/delete', ['password' => 'secret']));
    }

    public function test_another_user_keeps_their_own_budget(): void
    {
        $attacker = $this->user();
        $other    = $this->user();

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->actingAs($attacker)->postJson(route('kinetix.confidential.unlock'), ['password' => 'wrong']);
        }

        $this->actingAs($other)->postJson(route('kinetix.confidential.unlock'), ['password' => 'secret'])->assertOk();
    }

    public function test_the_limit_can_be_turned_off(): void
    {
        config()->set('kinetix.password_confirmation.throttle', false);
        $user = $this->user();

        for ($attempt = 1; $attempt <= 8; $attempt++) {
            $this->actingAs($user)->postJson(route('kinetix.confidential.unlock'), ['password' => 'wrong'])->assertStatus(422);
        }
    }

    #[DefineEnvironment('withMalformedLimit')]
    public function test_a_malformed_limit_uses_the_default_and_doctor_warns(): void
    {
        $this->assertSame([5, 1], KinetixThrottle::parse('abc', '5,1'));

        $user = $this->user();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->actingAs($user)->postJson(route('kinetix.confidential.unlock'), ['password' => 'wrong'])->assertStatus(422);
        }

        $this->assertThrottled($this->actingAs($user)->postJson(route('kinetix.confidential.unlock'), ['password' => 'wrong']));

        $this->artisan('kinetix:doctor', ['--json' => true])
            ->expectsOutputToContain('kinetix.password_confirmation.throttle')
            ->assertExitCode(0);
    }

    public function test_an_inertia_password_change_over_the_limit_goes_back_with_a_field_error(): void
    {
        $user = $this->user();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->actingAs($user)->postJson(route('kinetix.confidential.unlock'), ['password' => 'wrong']);
        }

        $response = $this->actingAs($user)
            ->withHeaders(['X-Inertia' => 'true'])
            ->from('/account/password')
            ->post(route('kinetix.password.change'), [
                'current_password'      => 'secret',
                'password'              => 'A-much-better-one-42!',
                'password_confirmation' => 'A-much-better-one-42!',
            ]);

        $response->assertStatus(303)->assertRedirect('/account/password');
        $response->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('secret', (string) $user->fresh()?->getAttribute('password')));
    }

    public function test_every_password_route_names_the_shared_limiter(): void
    {
        foreach ([
            'kinetix.confidential.unlock',
            'kinetix.gdpr.delete',
            'kinetix.sessions.destroy-others',
            'kinetix.connected-accounts.password',
            'kinetix.password.change',
        ] as $name) {
            $this->assertContains(
                'throttle:kinetix-password',
                Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [],
                "{$name} does not share the password budget.",
            );
        }
    }

    #[DefineEnvironment('withClientProfile')]
    public function test_a_profile_password_route_shares_the_budget(): void
    {
        $this->assertContains(
            'throttle:kinetix-password',
            Route::getRoutes()->getByName('kinetix.password.client.change')?->gatherMiddleware() ?? [],
        );
    }

    private function user(): PctUser
    {
        return PctUser::create(['name' => 'Ada', 'email' => 'ada'.uniqid().'@example.com', 'password' => Hash::make('secret')]);
    }

    private function assertThrottled(TestResponse $response): void
    {
        $response->assertStatus(429)
            ->assertJsonPath('code', 'throttled')
            ->assertHeader('Retry-After');

        $this->assertIsInt($response->json('retry_after'));
        $this->assertGreaterThan(0, $response->json('retry_after'));
    }
}
