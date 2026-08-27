<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;

/**
 * A password policy nobody applies and an identity field with no column both
 * fail in total silence — which is exactly what `kinetix:doctor` is for.
 */
class DoctorCredentialsTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.credentials.enabled', true);
    }

    private function usersTable(bool $withPolicyColumns = true, bool $withIdentityColumns = true): void
    {
        Schema::create('users', function (Blueprint $table) use ($withPolicyColumns, $withIdentityColumns) {
            $table->increments('id');
            $table->string('email')->nullable();

            if ($withPolicyColumns) {
                $table->timestamp('password_changed_at')->nullable();
                $table->boolean('must_change_password')->default(false);
            }

            if ($withIdentityColumns) {
                $table->string('username')->nullable();
                $table->string('phone')->nullable();
            }
        });
    }

    public function test_missing_policy_columns_are_an_error(): void
    {
        $this->usersTable(withPolicyColumns: false);

        // Without them the observer stands down: nothing is stamped, so no
        // password can ever expire.
        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('missing the password policy columns')
            ->assertFailed();
    }

    public function test_expiry_without_the_middleware_is_a_warning(): void
    {
        $this->usersTable();
        config()->set('kinetix.credentials.passwords.expires_after_days', 90);

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('nobody is ever asked to change theirs')
            ->assertSuccessful();
    }

    public function test_no_rules_configured_is_reported_as_such(): void
    {
        $this->usersTable();

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('no password rules configured')
            ->assertSuccessful();
    }

    public function test_an_identity_field_without_a_column_is_an_error(): void
    {
        $this->usersTable(withIdentityColumns: false);
        config()->set('kinetix.credentials.identity.fields', ['email', 'username']);

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('no column for accepted login field')
            ->assertFailed();
    }

    public function test_a_username_pattern_that_accepts_an_email_is_a_warning(): void
    {
        $this->usersTable();
        config()->set('kinetix.credentials.identity.fields', ['email', 'username']);
        config()->set('kinetix.credentials.identity.username_pattern', '/^.{3,64}$/');

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('accepts an email address')
            ->assertSuccessful();
    }

    public function test_the_shipped_username_pattern_is_not_flagged(): void
    {
        $this->usersTable();
        config()->set('kinetix.credentials.identity.fields', ['email', 'username']);

        $this->artisan('kinetix:doctor')
            ->doesntExpectOutputToContain('accepts an email address')
            ->assertSuccessful();
    }

    public function test_phone_logins_without_a_default_country_are_a_warning(): void
    {
        $this->usersTable();
        config()->set('kinetix.credentials.identity.fields', ['email', 'phone']);
        config()->set('kinetix.credentials.identity.phone_country', '');

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('two accounts')
            ->assertSuccessful();
    }

    public function test_nothing_is_reported_when_the_module_is_off(): void
    {
        config()->set('kinetix.credentials.enabled', false);

        $this->artisan('kinetix:doctor')
            ->doesntExpectOutputToContain('Credentials')
            ->assertSuccessful();
    }
}
