<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\ConnectedAccounts\ConnectedAccount;
use Happones\Kinetix\ConnectedAccounts\KinetixConnectedAccounts;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\SocialiteServiceProvider;
use Mockery;

class SocialUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['password'];
}

/**
 * A minimal Socialite user stub.
 */
class FakeSocialUser implements SocialiteUser
{
    public ?string $token = 'access-token';

    public ?string $refreshToken = 'refresh-token';

    public ?int $expiresIn = 3600;

    /**
     * The raw provider payload, like Socialite's AbstractUser::$user.
     *
     * @var array<string, mixed>
     */
    public array $user = [];

    public function __construct(
        private string $id = 'gh-1',
        private ?string $email = 'ada@example.com',
        private ?string $name = 'Ada Lovelace',
        private ?string $nickname = 'ada',
    ) {}

    public function getId()
    {
        return $this->id;
    }

    public function getNickname()
    {
        return $this->nickname;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getEmail()
    {
        return $this->email;
    }

    public function getAvatar()
    {
        return 'https://example.com/avatar.png';
    }
}

class ConnectedAccountsTest extends TestCase
{
    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [
            SocialiteServiceProvider::class,
        ]);
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.connected_accounts.enabled', true);
        $app['config']->set('kinetix.connected_accounts.login_enabled', true);
        $app['config']->set('kinetix.connected_accounts.providers', [
            'github'  => ['label' => 'GitHub', 'icon' => 'github', 'color' => '#181717'],
            'google'  => ['label' => 'Google', 'icon' => 'google', 'color' => '#4285F4'],
            'discord' => ['label' => 'Discord', 'icon' => 'discord', 'color' => '#5865F2'],
        ]);
        $app['config']->set('auth.providers.users.model', SocialUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->rememberToken();
        });

        Schema::create('kinetix_connected_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('provider');
            $table->string('provider_id');
            $table->string('nickname')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->text('avatar')->nullable();
            $table->text('token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_id']);
            $table->unique(['user_id', 'provider']);
        });
    }

    protected function tearDown(): void
    {
        KinetixConnectedAccounts::flush();
        SocialLoginCallbacks::$verifiedCalls  = [];
        SocialLoginCallbacks::$challengeCalls = [];
        Mockery::close();

        parent::tearDown();
    }

    private function fakeProvider(FakeSocialUser $user): void
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($user);

        Socialite::shouldReceive('driver')->andReturn($provider);
    }

    public function test_link_callback_attaches_the_provider_to_the_user(): void
    {
        $user = SocialUser::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => Hash::make('secret')]);
        $this->fakeProvider(new FakeSocialUser);

        $this->actingAs($user)
            ->get('/_kinetix/connected-accounts/callback/github')
            ->assertRedirect();

        $this->assertDatabaseHas('kinetix_connected_accounts', [
            'user_id'     => $user->id,
            'provider'    => 'github',
            'provider_id' => 'gh-1',
            'email'       => 'ada@example.com',
        ]);
    }

    public function test_link_rejects_an_identity_owned_by_another_user(): void
    {
        $other = SocialUser::create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => Hash::make('secret')]);
        ConnectedAccount::create(['user_id' => $other->id, 'provider' => 'github', 'provider_id' => 'gh-1']);

        $user = SocialUser::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => Hash::make('secret')]);
        $this->fakeProvider(new FakeSocialUser);

        $this->actingAs($user)->get('/_kinetix/connected-accounts/callback/github');

        // The identity stays with the original owner.
        $this->assertDatabaseMissing('kinetix_connected_accounts', [
            'user_id'  => $user->id,
            'provider' => 'github',
        ]);
    }

    public function test_index_returns_accounts_providers_and_password_state(): void
    {
        $user = SocialUser::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => Hash::make('secret')]);
        ConnectedAccount::create(['user_id' => $user->id, 'provider' => 'github', 'provider_id' => 'gh-1', 'email' => 'ada@example.com']);

        $response = $this->actingAs($user)->getJson('/_kinetix/connected-accounts');

        $response->assertOk()
            ->assertJsonPath('hasPassword', true)
            ->assertJsonPath('providers.0.key', 'github')
            ->assertJsonPath('providers.0.linked', true)
            ->assertJsonPath('accounts.0.provider', 'github');
    }

    public function test_unlink_removes_the_account(): void
    {
        $user    = SocialUser::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => Hash::make('secret')]);
        $account = ConnectedAccount::create(['user_id' => $user->id, 'provider' => 'github', 'provider_id' => 'gh-1']);

        $this->actingAs($user)
            ->deleteJson("/_kinetix/connected-accounts/{$account->id}")
            ->assertOk();

        $this->assertDatabaseMissing('kinetix_connected_accounts', ['id' => $account->id]);
    }

    public function test_unlink_is_blocked_for_the_last_method_without_a_password(): void
    {
        $user    = SocialUser::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => null]);
        $account = ConnectedAccount::create(['user_id' => $user->id, 'provider' => 'github', 'provider_id' => 'gh-1']);

        $this->actingAs($user)
            ->deleteJson("/_kinetix/connected-accounts/{$account->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('kinetix_connected_accounts', ['id' => $account->id]);
    }

    public function test_social_only_user_can_set_a_password_without_the_current_one(): void
    {
        $user = SocialUser::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => null]);

        $this->actingAs($user)
            ->postJson('/_kinetix/connected-accounts/password', [
                'password'              => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertOk()
            ->assertJsonPath('hasPassword', true);

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_unknown_provider_is_rejected(): void
    {
        $user = SocialUser::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => Hash::make('secret')]);

        $this->actingAs($user)
            ->get('/_kinetix/connected-accounts/callback/gitlab')
            ->assertNotFound();
    }

    public function test_guest_login_flow_creates_links_and_authenticates(): void
    {
        $this->fakeProvider(new FakeSocialUser(id: 'gh-9', email: 'grace@example.com', name: 'Grace Hopper'));

        $this->get('/_kinetix/connected-accounts/login/callback/github')->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'grace@example.com']);
        $this->assertDatabaseHas('kinetix_connected_accounts', ['provider' => 'github', 'provider_id' => 'gh-9']);
        $this->assertAuthenticated();

        // The social-created user has no usable password yet.
        $created = SocialUser::where('email', 'grace@example.com')->first();
        $this->assertNull($created->password);
    }

    public function test_login_by_an_unverified_email_does_not_take_over_the_existing_account(): void
    {
        $victim = SocialUser::create(['name' => 'Victim', 'email' => 'victim@example.com', 'password' => Hash::make('secret')]);
        $this->fakeProvider(new FakeSocialUser(id: 'dc-1', email: 'victim@example.com'));

        $this->get('/_kinetix/connected-accounts/login/callback/discord')
            ->assertRedirect('/login')
            ->assertSessionHas('error', __('kinetix.connected_account_sign_in_to_link', ['provider' => 'Discord']));

        $this->assertGuest();
        $this->assertDatabaseMissing('kinetix_connected_accounts', ['user_id' => $victim->id]);
    }

    public function test_link_by_email_never_refuses_even_a_trusted_provider(): void
    {
        config()->set('kinetix.connected_accounts.link_by_email', 'never');
        SocialUser::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => Hash::make('secret')]);
        $this->fakeProvider(new FakeSocialUser);

        $this->get('/_kinetix/connected-accounts/login/callback/github')->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_login_by_a_verified_email_claim_links_and_signs_in(): void
    {
        $user             = SocialUser::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => Hash::make('secret')]);
        $socialUser       = new FakeSocialUser(id: 'go-1');
        $socialUser->user = ['email_verified' => true];
        $this->fakeProvider($socialUser);

        $this->get('/_kinetix/connected-accounts/login/callback/google')->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('kinetix_connected_accounts', ['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'go-1']);
    }

    public function test_a_trusted_email_provider_links_by_email(): void
    {
        $user = SocialUser::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => Hash::make('secret')]);
        $this->fakeProvider(new FakeSocialUser);

        $this->get('/_kinetix/connected-accounts/login/callback/github')->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_email_verified_callback_can_vouch_for_an_email(): void
    {
        config()->set('kinetix.connected_accounts.email_verified', [SocialLoginCallbacks::class, 'verified']);
        $user = SocialUser::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => Hash::make('secret')]);
        $this->fakeProvider(new FakeSocialUser(id: 'dc-1'));

        $this->get('/_kinetix/connected-accounts/login/callback/discord')->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertSame([['dc-1', 'discord']], SocialLoginCallbacks::$verifiedCalls);
    }

    public function test_the_email_verified_callback_overrides_the_trusted_providers(): void
    {
        config()->set('kinetix.connected_accounts.email_verified', [SocialLoginCallbacks::class, 'distrust']);
        SocialUser::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => Hash::make('secret')]);
        $socialUser       = new FakeSocialUser;
        $socialUser->user = ['email_verified' => true];
        $this->fakeProvider($socialUser);

        $this->get('/_kinetix/connected-accounts/login/callback/github')->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_an_unverified_email_never_creates_an_account(): void
    {
        $this->fakeProvider(new FakeSocialUser(id: 'dc-2', email: 'future-victim@example.com'));

        $this->get('/_kinetix/connected-accounts/login/callback/discord')
            ->assertRedirect('/login')
            ->assertSessionHas('error', __('kinetix.connected_account_email_unverified', ['provider' => 'Discord']));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'future-victim@example.com']);
        $this->assertDatabaseCount('kinetix_connected_accounts', 0);
    }

    public function test_a_verified_email_creates_the_account(): void
    {
        $socialUser       = new FakeSocialUser(id: 'go-2', email: 'grace@example.com');
        $socialUser->user = ['email_verified' => true];
        $this->fakeProvider($socialUser);

        $this->get('/_kinetix/connected-accounts/login/callback/google')->assertRedirect('/');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'grace@example.com']);
    }

    public function test_link_by_email_always_still_creates_from_an_unverified_email(): void
    {
        config()->set('kinetix.connected_accounts.link_by_email', 'always');
        $this->fakeProvider(new FakeSocialUser(id: 'dc-3', email: 'new@example.com'));

        $this->get('/_kinetix/connected-accounts/login/callback/discord')->assertRedirect('/');

        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
    }

    public function test_an_existing_link_signs_in_its_owner_even_when_the_email_differs(): void
    {
        $owner = SocialUser::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => Hash::make('secret')]);
        ConnectedAccount::create(['user_id' => $owner->id, 'provider' => 'discord', 'provider_id' => 'dc-7']);
        $this->fakeProvider(new FakeSocialUser(id: 'dc-7', email: 'changed@example.com'));

        $this->get('/_kinetix/connected-accounts/login/callback/discord')->assertRedirect('/');

        $this->assertAuthenticatedAs($owner);
        $this->assertDatabaseMissing('users', ['email' => 'changed@example.com']);
    }

    public function test_link_by_email_always_keeps_the_legacy_behaviour(): void
    {
        config()->set('kinetix.connected_accounts.link_by_email', 'always');
        $user = SocialUser::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => Hash::make('secret')]);
        $this->fakeProvider(new FakeSocialUser(id: 'dc-1'));

        $this->get('/_kinetix/connected-accounts/login/callback/discord')->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('kinetix_connected_accounts', ['user_id' => $user->id, 'provider' => 'discord']);
    }

    public function test_a_two_factor_user_is_sent_to_the_challenge_instead_of_being_signed_in(): void
    {
        Route::get('/two-factor-challenge', static fn (): string => 'challenge')->name('two-factor.login');
        app('router')->getRoutes()->refreshNameLookups();

        $user = SocialUser::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => Hash::make('secret'), 'two_factor_secret' => 'encrypted-secret']);
        ConnectedAccount::create(['user_id' => $user->id, 'provider' => 'github', 'provider_id' => 'gh-1']);
        $this->fakeProvider(new FakeSocialUser);

        $this->get('/_kinetix/connected-accounts/login/callback/github')
            ->assertRedirect('/two-factor-challenge')
            ->assertSessionHas('login.id', $user->id)
            ->assertSessionHas('login.remember', true)
            ->assertSessionHas('url.intended', url('/'));

        $this->assertGuest();
    }

    public function test_a_two_factor_user_is_refused_when_no_challenge_route_exists(): void
    {
        $user = SocialUser::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => Hash::make('secret'), 'two_factor_secret' => 'encrypted-secret']);
        ConnectedAccount::create(['user_id' => $user->id, 'provider' => 'github', 'provider_id' => 'gh-1']);
        $this->fakeProvider(new FakeSocialUser);

        $this->get('/_kinetix/connected-accounts/login/callback/github')
            ->assertRedirect('/login')
            ->assertSessionHas('error', __('kinetix.connected_account_two_factor_required'));

        $this->assertGuest();
    }

    public function test_the_two_factor_challenge_callback_takes_over_the_hand_off(): void
    {
        config()->set('kinetix.connected_accounts.two_factor_challenge', [SocialLoginCallbacks::class, 'challenge']);

        $user = SocialUser::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => Hash::make('secret')]);
        ConnectedAccount::create(['user_id' => $user->id, 'provider' => 'github', 'provider_id' => 'gh-1']);
        $this->fakeProvider(new FakeSocialUser);

        $this->get('/_kinetix/connected-accounts/login/callback/github')->assertRedirect('/custom-2fa');

        $this->assertGuest();
        $this->assertSame([[$user->id, true]], SocialLoginCallbacks::$challengeCalls);
    }
}

/**
 * Callable-array config callbacks for the social login tests.
 */
class SocialLoginCallbacks
{
    /**
     * @var list<array{0: string, 1: string}>
     */
    public static array $verifiedCalls = [];

    /**
     * @var list<array{0: int|string, 1: bool}>
     */
    public static array $challengeCalls = [];

    public static function verified(object $socialUser, string $provider): bool
    {
        static::$verifiedCalls[] = [(string) $socialUser->getId(), $provider];

        return true;
    }

    public static function distrust(object $socialUser, string $provider): bool
    {
        return false;
    }

    public static function challenge(Authenticatable $user, Request $request, bool $remember): ?RedirectResponse
    {
        static::$challengeCalls[] = [$user->getAuthIdentifier(), $remember];

        return redirect('/custom-2fa');
    }
}
