<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Credentials\IdentityResolver;
use Happones\Kinetix\Credentials\KinetixIdentity;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class ProfUser extends Authenticatable
{
    protected $table = 'prof_users';

    public $timestamps = false;

    protected $guarded = [];
}

class ProfClient extends Authenticatable
{
    protected $table = 'prof_clients';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * Credential PROFILES — a second authenticatable (`Client`) alongside the
 * default `User`, each resolving against its own model and identity fields.
 */
class CredentialProfilesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.credentials.enabled', true);
        $app['config']->set('kinetix.credentials.user_model', ProfUser::class);
        $app['config']->set('kinetix.credentials.identity.fields', ['email']);
        $app['config']->set('kinetix.credentials.profiles', [
            'client' => [
                'user_model' => ProfClient::class,
                'identity'   => ['fields' => ['email', 'username']],
            ],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('prof_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
        });

        Schema::create('prof_clients', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
        });
    }

    public function test_each_profile_resolves_against_its_own_model(): void
    {
        $user   = ProfUser::create(['email' => 'a@example.com', 'password' => Hash::make('pw')]);
        $client = ProfClient::create(['email' => 'a@example.com', 'password' => Hash::make('pw')]);

        // Same email, different tables — each profile finds ITS record.
        $this->assertInstanceOf(ProfUser::class, KinetixIdentity::resolve('a@example.com'));
        $this->assertInstanceOf(ProfClient::class, KinetixIdentity::resolve('a@example.com', 'client'));

        $this->assertSame($user->id, KinetixIdentity::resolve('a@example.com')->id);
        $this->assertSame($client->id, KinetixIdentity::resolve('a@example.com', 'client')->id);
    }

    public function test_profile_honours_its_own_identity_fields(): void
    {
        // The default profile accepts only email; the client profile also
        // accepts username.
        $this->assertSame(['email'], IdentityResolver::for(null)->fields());
        $this->assertSame(['email', 'username'], IdentityResolver::for('client')->fields());

        // A username login resolves on the client profile, not the default.
        ProfClient::create(['username' => 'neo', 'password' => Hash::make('pw')]);

        $this->assertNull(KinetixIdentity::resolve('neo'));              // default: username not accepted
        $this->assertNotNull(KinetixIdentity::resolve('neo', 'client')); // client: accepted
    }

    public function test_attempt_verifies_the_password_against_the_profiles_model(): void
    {
        ProfClient::create(['email' => 'c@example.com', 'password' => Hash::make('secret')]);

        $this->assertNotNull(KinetixIdentity::attempt('c@example.com', 'secret', 'client'));
        $this->assertNull(KinetixIdentity::attempt('c@example.com', 'wrong', 'client'));
        // The default profile has no such record.
        $this->assertNull(KinetixIdentity::attempt('c@example.com', 'secret'));
    }

    /**
     * An unknown profile used to fall back to the default model: a client
     * portal could log a `User` into the client guard, and that guard then
     * loads the Client with the User's id — another person's account.
     */
    public function test_an_unknown_profile_is_refused(): void
    {
        ProfUser::create(['email' => 'd@example.com', 'password' => Hash::make('pw')]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown credential profile [nope]');

        KinetixIdentity::resolve('d@example.com', 'nope');
    }

    public function test_a_profile_without_a_user_model_is_refused(): void
    {
        config()->set('kinetix.credentials.profiles.broken', ['identity' => ['fields' => ['email']]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Credential profile [broken] has no user_model');

        IdentityResolver::for('broken');
    }

    public function test_blueprint_macro_adds_the_password_columns_to_any_table(): void
    {
        Schema::create('prof_portal', function (Blueprint $table) {
            $table->increments('id');
            $table->kinetixPasswordColumns();
        });

        $this->assertTrue(Schema::hasColumn('prof_portal', 'password_changed_at'));
        $this->assertTrue(Schema::hasColumn('prof_portal', 'must_change_password'));
    }
}
