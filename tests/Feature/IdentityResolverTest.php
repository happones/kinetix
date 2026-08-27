<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Credentials\KinetixIdentity;
use Happones\Kinetix\Credentials\KinetixPasswords;
use Happones\Kinetix\Credentials\PasswordObserver;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class IdUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password_changed_at'  => 'datetime',
            'must_change_password' => 'boolean',
        ];
    }
}

/**
 * Resolving a login when "email" is not the only answer.
 *
 * Two properties carry the security of the whole thing, and most of what
 * follows is proving them: a login is CLASSIFIED before it is queried (so an
 * email can never be matched against someone's username), and every value is
 * normalized identically going in and coming out (so one phone number cannot
 * become two accounts).
 */
class IdentityResolverTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.credentials.enabled', true);
        $app['config']->set('kinetix.credentials.user_model', IdUser::class);
        $app['config']->set('kinetix.membership.user_model', IdUser::class);
        $app['config']->set('kinetix.credentials.identity.fields', ['email', 'username', 'phone']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('username')->nullable()->unique();
            $table->string('phone')->nullable()->unique();
            $table->string('password')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->boolean('must_change_password')->default(false);
        });

        Schema::create('kinetix_password_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->index();
            $table->string('password');
            $table->timestamp('created_at')->nullable();
        });

        PasswordObserver::flush();
    }

    protected function tearDown(): void
    {
        PasswordObserver::flush();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function user(array $attributes = [], string $password = 'secret-one'): IdUser
    {
        return IdUser::create([...$attributes, 'password' => Hash::make($password)]);
    }

    // -- Defaults are today's behavior ----------------------------------------

    public function test_the_default_is_email_only(): void
    {
        config()->set('kinetix.credentials.identity.fields', ['email']);

        $this->assertSame(['email'], KinetixIdentity::fields());
        $this->assertFalse(KinetixIdentity::enabled(), 'email-only is not "identity configured"');
    }

    public function test_an_unknown_field_is_ignored(): void
    {
        config()->set('kinetix.credentials.identity.fields', ['email', 'nickname', 'phone']);

        $this->assertSame(['email', 'phone'], KinetixIdentity::fields());
    }

    public function test_an_empty_field_list_falls_back_to_email(): void
    {
        config()->set('kinetix.credentials.identity.fields', []);

        $this->assertSame(['email'], KinetixIdentity::fields());
    }

    // -- Classification (the cross-match guard) --------------------------------

    public function test_an_email_is_only_ever_classified_as_an_email(): void
    {
        $this->assertSame(['email'], KinetixIdentity::classify('jane@example.com'));
    }

    public function test_an_email_cannot_be_matched_against_a_username(): void
    {
        // The attack: register somebody else's email address as your username
        // and be found by it.
        $impostor = $this->user(['name' => 'Impostor', 'username' => 'jane@example.com'], 'impostor-pass');
        $this->user(['name' => 'Jane', 'email' => 'jane@example.com'], 'jane-pass');

        $resolved = KinetixIdentity::resolve('jane@example.com');

        $this->assertNotNull($resolved);
        $this->assertSame('Jane', $resolved->name);
        $this->assertNotSame($impostor->getKey(), $resolved->getKey());

        // And the impostor's own password does not get them in under it.
        $this->assertNull(KinetixIdentity::attempt('jane@example.com', 'impostor-pass'));
    }

    public function test_the_default_username_pattern_cannot_look_like_an_email(): void
    {
        // Belt and braces for the case above: `@` is not a username character,
        // so the collision cannot be created in the first place.
        $this->assertFalse(KinetixIdentity::classify('jane@example.com') === ['username']);

        $rules = KinetixIdentity::rules();
        $fails = Validator::make(['username' => 'jane@example.com'], ['username' => $rules['username']]);

        $this->assertTrue($fails->fails());
    }

    public function test_a_phone_shaped_login_is_classified_as_a_phone(): void
    {
        $this->assertSame(['phone'], KinetixIdentity::classify('+52 55 1234 5678'));
        $this->assertSame(['phone'], KinetixIdentity::classify('(55) 1234-5678'));
    }

    public function test_a_short_number_is_not_a_phone(): void
    {
        // A four-digit staff code is a username, not a phone number.
        $this->assertNotContains('phone', KinetixIdentity::classify('1234'));
    }

    public function test_a_field_that_is_not_accepted_is_never_classified(): void
    {
        config()->set('kinetix.credentials.identity.fields', ['email']);

        $this->assertSame([], KinetixIdentity::classify('juan.perez'));
        $this->assertSame([], KinetixIdentity::classify('+52 55 1234 5678'));
    }

    // -- Normalization ---------------------------------------------------------

    public function test_a_phone_normalizes_to_e164(): void
    {
        config()->set('kinetix.credentials.identity.phone_country', 'MX');

        foreach (['+52 55 1234 5678', '0052 55 1234 5678'] as $written) {
            $this->assertSame('+525512345678', KinetixIdentity::normalize('phone', $written), $written);
        }

        // A local number gets the configured country's dial code.
        $this->assertSame('+525512345678', KinetixIdentity::normalize('phone', '55 1234 5678'));

        // STORAGE is strict on purpose: without a `+` or `00`, digits are a
        // LOCAL number. Reading them as already-international would be a guess,
        // and a guess that is wrong for every local number starting with 52.
        $this->assertSame('+525212345678', KinetixIdentity::normalize('phone', '5212345678'));
    }

    public function test_lookup_accepts_a_bare_number_written_with_its_country_code(): void
    {
        config()->set('kinetix.credentials.identity.phone_country', 'MX');

        // The ambiguity storage refuses to guess about is confined to lookup,
        // where both readings are matched against canonically-stored values and
        // at most one exists.
        $this->assertSame(
            ['+525252345678', '+5252345678'],
            KinetixIdentity::resolver()->phoneLookupCandidates('5252345678'),
        );

        $user = $this->user([
            'name'  => 'Empleado',
            'phone' => KinetixIdentity::normalize('phone', '55 1234 5678'),
        ]);

        // Typed in full, without the plus.
        $this->assertSame($user->getKey(), KinetixIdentity::resolve('525512345678')?->getKey());
    }

    public function test_a_local_number_is_left_alone_without_a_default_country(): void
    {
        config()->set('kinetix.credentials.identity.phone_country', '');

        // Inventing a country the number isn't from would be worse than leaving
        // it as typed.
        $this->assertSame('+5512345678', KinetixIdentity::normalize('phone', '55 1234 5678'));
    }

    public function test_email_and_username_are_lowercased(): void
    {
        $this->assertSame('jane@example.com', KinetixIdentity::normalize('email', '  Jane@Example.COM '));
        $this->assertSame('juan.perez', KinetixIdentity::normalize('username', 'Juan.Perez'));
    }

    public function test_one_phone_number_written_two_ways_resolves_to_one_user(): void
    {
        config()->set('kinetix.credentials.identity.phone_country', 'MX');

        $user = $this->user([
            'name'  => 'Empleado',
            'phone' => KinetixIdentity::normalize('phone', '55 1234 5678'),
        ]);

        foreach (['+52 55 1234 5678', '5512345678', '+525512345678', '525512345678'] as $typed) {
            $found = KinetixIdentity::resolve($typed);

            $this->assertNotNull($found, $typed);
            $this->assertSame($user->getKey(), $found->getKey(), $typed);
        }
    }

    // -- Resolution & attempt ---------------------------------------------------

    public function test_a_user_resolves_by_username_and_by_phone(): void
    {
        config()->set('kinetix.credentials.identity.phone_country', 'MX');

        $user = $this->user([
            'name'     => 'Empleado',
            'username' => 'juan.perez',
            'phone'    => KinetixIdentity::normalize('phone', '5512345678'),
        ]);

        $this->assertSame($user->getKey(), KinetixIdentity::resolve('juan.perez')?->getKey());
        $this->assertSame($user->getKey(), KinetixIdentity::resolve('Juan.Perez')?->getKey());
        $this->assertSame($user->getKey(), KinetixIdentity::resolve('+52 55 1234 5678')?->getKey());
    }

    public function test_attempt_verifies_the_password(): void
    {
        $user = $this->user(['name' => 'Empleado', 'username' => 'juan.perez'], 'correct-horse');

        $this->assertSame($user->getKey(), KinetixIdentity::attempt('juan.perez', 'correct-horse')?->getKey());
        $this->assertNull(KinetixIdentity::attempt('juan.perez', 'wrong'));
        $this->assertNull(KinetixIdentity::attempt('does.not.exist', 'correct-horse'));
    }

    public function test_attempt_refuses_an_expired_temporary_credential(): void
    {
        config()->set('kinetix.credentials.passwords.temporary_ttl_hours', 24);

        $user  = $this->user(['name' => 'Empleado', 'username' => 'juan.perez']);
        $plain = KinetixPasswords::issueTemporary($user);

        // Handed over and used in time: fine.
        $this->assertNotNull(KinetixIdentity::attempt('juan.perez', $plain));

        $user->forceFill(['password_changed_at' => now()->subHours(25)])->saveQuietly();

        // Never used, now stale — an admin has to issue a new one.
        $this->assertNull(KinetixIdentity::attempt('juan.perez', $plain));
    }

    public function test_an_ambiguous_login_resolves_to_nobody(): void
    {
        config()->set('kinetix.credentials.identity.phone_country', 'MX');

        // An all-digit string is a legal username AND a plausible phone, so it
        // classifies as both. Two different people can then match it — one by
        // username, one by phone — and each column is still perfectly unique.
        $this->assertSame(['phone', 'username'], KinetixIdentity::classify('5512345678'));

        $this->user(['name' => 'By phone', 'phone' => KinetixIdentity::normalize('phone', '5512345678')]);
        $this->user(['name' => 'By username', 'username' => '5512345678']);

        // An ambiguous identity is worse than a failed login.
        $this->assertNull(KinetixIdentity::resolve('5512345678'));
        $this->assertNull(KinetixIdentity::attempt('5512345678', 'secret-one'));
    }

    public function test_a_blank_login_resolves_to_nobody(): void
    {
        $this->user(['name' => 'Empleado', 'username' => 'juan.perez']);

        $this->assertNull(KinetixIdentity::resolve(''));
        $this->assertNull(KinetixIdentity::resolve(null));
        $this->assertNull(KinetixIdentity::attempt(null, 'anything'));
    }

    // -- Validation rules ------------------------------------------------------

    public function test_the_rules_require_at_least_one_identifier(): void
    {
        $rules = KinetixIdentity::rules();

        $none = Validator::make(['email' => null, 'username' => null, 'phone' => null], $rules);
        $this->assertTrue($none->fails(), 'a user nobody can identify is not a user');

        $one = Validator::make(['email' => null, 'username' => 'juan.perez', 'phone' => null], $rules);
        $this->assertFalse($one->fails());
    }

    public function test_the_rules_enforce_uniqueness_and_ignore_the_record_being_updated(): void
    {
        $user = $this->user(['name' => 'Empleado', 'username' => 'juan.perez']);

        $taken = Validator::make(['username' => 'juan.perez'], KinetixIdentity::rules());
        $this->assertTrue($taken->fails());

        $itself = Validator::make(['username' => 'juan.perez'], KinetixIdentity::rules($user));
        $this->assertFalse($itself->fails());
    }

    public function test_with_only_email_the_rule_is_simply_required(): void
    {
        config()->set('kinetix.credentials.identity.fields', ['email']);

        $rules = KinetixIdentity::rules();

        $this->assertSame('required', $rules['email'][0]);
        $this->assertArrayNotHasKey('username', $rules);
    }
}
