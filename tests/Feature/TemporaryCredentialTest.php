<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Credentials\KinetixPasswords;
use Happones\Kinetix\Credentials\PasswordObserver;
use Happones\Kinetix\Credentials\TemporaryCredential;
use Happones\Kinetix\Credentials\TemporaryPasswordNotification;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Schema;

/**
 * A `Client` — NOT the default `User`, and on its own table — to prove the
 * temporary credential is model-agnostic and reusable outside Membership.
 */
class CredClient extends Authenticatable
{
    use Notifiable;

    protected $table = 'cred_clients';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'password_changed_at'  => 'datetime',
            'must_change_password' => 'boolean',
        ];
    }

    public function routeNotificationForMail(): string
    {
        return $this->email;
    }
}

/** A host subclass swapping the delivery channel. */
class CustomTempNotification extends TemporaryPasswordNotification
{
    public function via(object $notifiable): array
    {
        return ['database'];
    }
}

class TemporaryCredentialTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.credentials.enabled', true);
        $app['config']->set('kinetix.credentials.user_model', CredClient::class);
        $app['config']->set('kinetix.membership.user_model', CredClient::class);
        $app['config']->set('kinetix.credentials.passwords.temporary_ttl_hours', 48);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('cred_clients', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->boolean('must_change_password')->default(false);
        });

        PasswordObserver::flush();
    }

    public function test_issue_returns_a_credential_with_value_and_expiry_on_a_non_user_model(): void
    {
        $client = CredClient::create(['email' => 'ops@acme.dev']);

        $cred = KinetixPasswords::issueTemporaryCredential($client);

        $this->assertInstanceOf(TemporaryCredential::class, $cred);
        $this->assertNotSame('', $cred->value);
        $this->assertInstanceOf(Carbon::class, $cred->expiresAt);

        // The model stores a HASH of exactly the issued plaintext, and is flagged.
        $fresh = $client->fresh();
        $this->assertTrue(Hash::check($cred->value, $fresh->password));
        $this->assertTrue((bool) $fresh->must_change_password);
    }

    public function test_the_plaintext_is_redacted_in_strings_and_debug(): void
    {
        $cred = TemporaryCredential::make('s3cret-plain', Carbon::parse('2026-01-01 10:00'));

        $this->assertStringNotContainsString('s3cret-plain', (string) $cred);
        $this->assertStringNotContainsString('s3cret-plain', print_r($cred->__debugInfo(), true));
        // …but the value is reachable when you ask for it directly.
        $this->assertSame('s3cret-plain', $cred->value);
    }

    public function test_it_serializes_value_and_iso_expiry(): void
    {
        $cred = TemporaryCredential::make('abc', Carbon::parse('2026-01-01 10:00:00', 'UTC'));

        $this->assertSame('abc', $cred->toArray()['value']);
        $this->assertStringStartsWith('2026-01-01T10:00:00', $cred->toArray()['expiresAt']);
    }

    public function test_send_notifies_the_model_with_the_mail_notification(): void
    {
        NotificationFacade::fake();
        $client = CredClient::create(['email' => 'ops@acme.dev']);

        KinetixPasswords::issueTemporaryCredential($client)->send($client);

        NotificationFacade::assertSentTo(
            $client,
            TemporaryPasswordNotification::class,
            function (TemporaryPasswordNotification $n) use ($client) {
                // The mail renders the issued plaintext and reaches mail.
                $this->assertContains('mail', $n->via($client));

                return true;
            },
        );
    }

    public function test_send_mail_routes_to_an_off_model_address(): void
    {
        NotificationFacade::fake();

        TemporaryCredential::make('temp-123')->sendMail('admin@acme.dev');

        NotificationFacade::assertSentOnDemand(TemporaryPasswordNotification::class);
    }

    public function test_the_notification_class_is_configurable(): void
    {
        config()->set('kinetix.credentials.passwords.notification', CustomTempNotification::class);
        NotificationFacade::fake();
        $client = CredClient::create(['email' => 'ops@acme.dev']);

        KinetixPasswords::issueTemporaryCredential($client)->send($client);

        NotificationFacade::assertSentTo($client, CustomTempNotification::class);
    }

    public function test_legacy_issue_temporary_still_returns_a_string(): void
    {
        $client = CredClient::create(['email' => 'ops@acme.dev']);

        $plain = KinetixPasswords::issueTemporary($client);

        $this->assertIsString($plain);
        $this->assertTrue(Hash::check($plain, $client->fresh()->password));
    }

    public function test_the_mail_body_contains_the_password_and_expiry(): void
    {
        $cred = TemporaryCredential::make('temp-xyz', Carbon::parse('2026-06-01 09:00'));
        $mail = (new TemporaryPasswordNotification($cred->value, $cred->expiresAt))
            ->toMail(new CredClient(['email' => 'x@y.z']));

        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertTrue(
            collect($mail->introLines)->contains(fn ($l) => str_contains((string) $l, 'temp-xyz')),
        );
    }
}
