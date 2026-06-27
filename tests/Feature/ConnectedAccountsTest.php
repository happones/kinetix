<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\ConnectedAccounts\ConnectedAccount;
use Happones\Kinetix\ConnectedAccounts\KinetixConnectedAccounts;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;
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
            'github' => ['label' => 'GitHub', 'icon' => 'github', 'color' => '#181717'],
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
}
