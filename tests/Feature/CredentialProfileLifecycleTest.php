<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Carbon\CarbonImmutable;
use Happones\Kinetix\Credentials\KinetixPasswords;
use Happones\Kinetix\Credentials\PasswordHistory;
use Happones\Kinetix\Credentials\PasswordObserver;
use Happones\Kinetix\Credentials\PasswordPolicy;
use Happones\Kinetix\Credentials\TemporaryCredential;
use Happones\Kinetix\Credentials\TemporaryPasswordNotification;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;
use LogicException;

class LifeUser extends Authenticatable
{
    protected $table = 'life_users';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['password_changed_at' => 'datetime', 'must_change_password' => 'boolean'];
    }
}

class LifeClient extends Authenticatable
{
    protected $table = 'life_clients';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['password_changed_at' => 'datetime', 'must_change_password' => 'boolean'];
    }
}

/**
 * The password lifecycle for a credential PROFILE's model (a Client beside the
 * default User): observed, expiring and with its own history.
 */
class CredentialProfileLifecycleTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.credentials.enabled', true);
        $app['config']->set('kinetix.credentials.user_model', LifeUser::class);
        $app['config']->set('kinetix.credentials.profiles', [
            'client' => ['user_model' => LifeClient::class, 'guard' => 'client'],
        ]);
        $app['config']->set('auth.guards.client', ['driver' => 'session', 'provider' => 'clients']);
        $app['config']->set('auth.providers.clients', ['driver' => 'eloquent', 'model' => LifeClient::class]);
        $app['config']->set('kinetix.credentials.passwords.temporary_ttl_hours', 48);
        $app['config']->set('kinetix.credentials.passwords.expires_after_days', 30);
        $app['config']->set('kinetix.credentials.passwords.history', 3);
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['life_users', 'life_clients'] as $name) {
            Schema::create($name, static function (Blueprint $table): void {
                $table->increments('id');
                $table->string('email')->nullable();
                $table->string('password')->nullable();
                $table->kinetixPasswordColumns();
            });
        }

        (require __DIR__.'/../../database/migrations/2026_01_01_000032_create_kinetix_password_history_table.php')->up();
        (require __DIR__.'/../../database/migrations/2026_01_01_000038_add_authenticatable_type_to_kinetix_password_history_table.php')->up();

        PasswordObserver::flush();
        PasswordHistory::flush();
    }

    /**
     * Only the default model was observed: a Client's password was never
     * stamped, so its temporary credential had no expiry and never expired.
     */
    public function test_a_profile_model_gets_the_password_lifecycle(): void
    {
        $client = LifeClient::create(['email' => 'c@example.com', 'password' => Hash::make('first')]);

        $cred = KinetixPasswords::issueTemporaryCredential($client);

        $this->assertNotNull($cred->expiresAt);
        $this->travel(49)->hours();
        $this->assertTrue(KinetixPasswords::temporaryHasExpired($client->fresh()));

        // Choosing their own password clears the forced change.
        $client = $client->fresh();
        $client->forceFill(['password' => Hash::make('mine-now')])->save();
        $this->assertFalse((bool) $client->fresh()->must_change_password);
    }

    /**
     * The history was keyed by id alone: Client #1 read User #1's passwords,
     * and forgetting one erased the other's.
     */
    public function test_history_is_kept_per_model(): void
    {
        $user   = LifeUser::create(['email' => 'u@example.com', 'password' => Hash::make('StaffSecret1')]);
        $client = LifeClient::create(['email' => 'c@example.com', 'password' => Hash::make('ClientSecret1')]);
        $this->assertSame($user->id, $client->id);

        $user->forceFill(['password' => Hash::make('StaffSecret2')])->save();

        $this->assertTrue(KinetixPasswords::wasUsedBefore($user->fresh(), 'StaffSecret1'));
        $this->assertFalse(KinetixPasswords::wasUsedBefore($client->fresh(), 'StaffSecret1'));

        KinetixPasswords::forget($client);
        $this->assertTrue(KinetixPasswords::wasUsedBefore($user->fresh(), 'StaffSecret1'));
    }

    public function test_rows_from_before_the_type_column_belong_to_the_default_model(): void
    {
        $user   = LifeUser::create(['email' => 'u@example.com']);
        $client = LifeClient::create(['email' => 'c@example.com']);

        PasswordHistory::query()->create([
            'user_id'    => $user->id,
            'password'   => Hash::make('Legacy1'),
            'created_at' => now(),
        ]);

        $this->assertTrue(KinetixPasswords::wasUsedBefore($user, 'Legacy1'));
        $this->assertFalse(KinetixPasswords::wasUsedBefore($client, 'Legacy1'));
    }

    /**
     * `instanceof Illuminate\Support\Carbon` was false for CarbonImmutable,
     * so an app on immutable dates had expiry silently switched off.
     */
    public function test_expiry_works_with_immutable_dates(): void
    {
        Date::use(CarbonImmutable::class);

        try {
            $user = LifeUser::create(['email' => 'u@example.com', 'password' => Hash::make('pw')])->fresh();

            $this->assertInstanceOf(CarbonImmutable::class, $user->password_changed_at);
            $this->assertNotNull(KinetixPasswords::expiresAt($user));

            $this->travel(31)->days();
            $this->assertTrue(KinetixPasswords::isExpired($user));
        } finally {
            Date::useDefault();
        }
    }

    /**
     * Re-issuing to a user still flagged leaves the flag unchanged, and the
     * observer used to clear it: the second temporary password became a
     * permanent one.
     */
    public function test_reissuing_a_temporary_credential_keeps_the_forced_change(): void
    {
        $client = LifeClient::create(['email' => 'c@example.com', 'password' => Hash::make('first')]);

        KinetixPasswords::issueTemporaryCredential($client);
        $second = KinetixPasswords::issueTemporaryCredential($client->fresh());

        $this->assertTrue((bool) $client->fresh()->must_change_password);
        $this->assertNotNull($second->expiresAt);
    }

    public function test_the_queued_notification_is_encrypted(): void
    {
        $notification = new TemporaryPasswordNotification('s3cret');

        $this->assertInstanceOf(ShouldBeEncrypted::class, $notification);
        $this->assertTrue((new SendQueuedNotifications(collect(), $notification))->shouldBeEncrypted);
        $this->assertStringNotContainsString('s3cret', print_r($notification->__debugInfo(), true));
    }

    public function test_a_channel_the_notification_does_not_deliver_over_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not deliver over [vonage]');

        TemporaryCredential::make('s3cret')->sendVia('vonage', '+525512345678');
    }

    public function test_a_misconfigured_notification_class_is_refused(): void
    {
        config()->set('kinetix.credentials.passwords.notification', \stdClass::class);

        $this->expectException(InvalidArgumentException::class);

        TemporaryCredential::make('s3cret')->resolveNotification();
    }

    public function test_a_temporary_credential_cannot_be_serialized(): void
    {
        $this->expectException(LogicException::class);

        serialize(TemporaryCredential::make('s3cret'));
    }

    /**
     * The change-password routes sat behind the default guard: a client with
     * an expired password was sent to the staff login.
     */
    public function test_a_profile_with_a_guard_gets_its_own_change_screen(): void
    {
        Route::middleware(['web', 'auth:client', 'kinetix.password'])
            ->get('/portal', fn () => 'portal')
            ->name('portal.home');

        $client = LifeClient::create(['email' => 'c@example.com', 'password' => Hash::make('first')]);
        KinetixPasswords::forceChange($client);
        $client = $client->fresh();

        $this->actingAs($client, 'client')
            ->get('/portal')
            ->assertRedirect(route('kinetix.password.client.change.show'));

        // Inertia's root view, for the full-page render.
        $views = sys_get_temp_dir().'/kinetix-profile-views';
        is_dir($views) || mkdir($views, 0o777, true);
        file_put_contents($views.'/app.blade.php', '<html><body>@inertia</body></html>');
        View::addLocation($views);

        $page = $this->actingAs($client, 'client')
            ->get(route('kinetix.password.client.change.show'))
            ->assertOk()
            ->viewData('page');
        $this->assertSame(route('kinetix.password.client.change'), $page['props']['action']);
        $this->assertSame(
            route('kinetix.password.client.change.show'),
            app(PasswordPolicy::class)->state($client)['changeUrl'],
        );

        $this->actingAs($client, 'client')
            ->post(route('kinetix.password.client.change'), [
                'password'              => 'A-much-better-one-42!',
                'password_confirmation' => 'A-much-better-one-42!',
            ])
            ->assertRedirect();

        $this->assertFalse((bool) $client->fresh()->must_change_password);
        $this->assertTrue(Hash::check('A-much-better-one-42!', $client->fresh()->password));
    }
}
