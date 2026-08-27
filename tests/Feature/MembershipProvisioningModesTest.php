<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Credentials\KinetixPasswords;
use Happones\Kinetix\Credentials\PasswordObserver;
use Happones\Kinetix\Membership\MemberActivationNotification;
use Happones\Kinetix\Membership\MemberProvision;
use Happones\Kinetix\Membership\MemberProvisionStatus;
use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Tests\Concerns\CreatesMembershipTables;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;

class ModeUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $guard_name = 'web';

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
 * The subclass a host writes for their SMS provider — Kinetix ships the text,
 * the channel's message object comes from that provider's own package.
 */
class SmsActivation extends MemberActivationNotification
{
    /** @return array<string, string> */
    public function toTestsms(object $notifiable): array
    {
        return ['content' => $this->smsContent()];
    }
}

/**
 * Provisioning a member who has no email address.
 *
 * Two axes, both defaulting to today's behavior: `provisioning` decides whether
 * an account exists before the person shows up, and `delivery` decides whether
 * anything is sent at all. The interesting combination is `direct` + `manual` —
 * the owner creates the account and hands over a temporary password in person,
 * which is the only shape that works with no channel whatsoever.
 */
class MembershipProvisioningModesTest extends TestCase
{
    use CreatesMembershipTables;

    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PermissionServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.membership.enabled', true);
        $app['config']->set('kinetix.membership.user_model', ModeUser::class);
        $app['config']->set('kinetix.membership.assignable_roles', ['editor', 'viewer']);

        // The password policy is what makes a temporary credential safe.
        $app['config']->set('kinetix.credentials.enabled', true);
        $app['config']->set('kinetix.credentials.user_model', ModeUser::class);
        $app['config']->set('kinetix.credentials.identity.fields', ['email', 'username', 'phone']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMembershipTables();
        PasswordObserver::flush();

        foreach (KinetixPermissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (['editor', 'viewer'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    protected function tearDown(): void
    {
        PasswordObserver::flush();

        parent::tearDown();
    }

    private function manager(bool $withCredentials = true): ModeUser
    {
        $user = ModeUser::create(['name' => 'Manager', 'email' => 'manager@example.com']);

        foreach (['members.provision', 'members.viewAny', 'members.update', 'members.revoke'] as $ability) {
            $user->givePermissionTo($ability);
        }

        if ($withCredentials) {
            $user->givePermissionTo('members.credentials');
        }

        return $user;
    }

    // -- The default is unchanged ---------------------------------------------

    public function test_the_default_still_mails_a_link_and_creates_no_user(): void
    {
        Notification::fake();

        $this->actingAs($this->manager())
            ->postJson('/_kinetix/members', ['email' => 'new@example.com', 'role' => 'editor'])
            ->assertCreated()
            // No credential is handed back when one was sent.
            ->assertJsonMissingPath('credential');

        Notification::assertSentOnDemand(MemberActivationNotification::class);
        $this->assertSame(0, ModeUser::query()->where('email', 'new@example.com')->count());
    }

    // -- delivery: manual ------------------------------------------------------

    public function test_manual_delivery_sends_nothing_and_hands_back_the_link(): void
    {
        Notification::fake();
        config()->set('kinetix.membership.delivery', 'manual');

        $response = $this->actingAs($this->manager())
            ->postJson('/_kinetix/members', ['email' => 'new@example.com', 'role' => 'editor'])
            ->assertCreated();

        Notification::assertNothingSent();

        $this->assertSame('link', $response->json('credential.type'));
        $this->assertStringContainsString('members/activate/', (string) $response->json('credential.value'));
        $this->assertNotNull($response->json('credential.expiresAt'));
    }

    public function test_a_member_with_no_channel_gets_the_link_handed_back_even_on_mail_delivery(): void
    {
        Notification::fake();
        config()->set('kinetix.membership.identifier', 'username');

        // Configured to send, but there is nothing to send TO — handing the
        // link over beats failing silently.
        $response = $this->actingAs($this->manager())
            ->postJson('/_kinetix/members', ['username' => 'juan.perez', 'role' => 'editor'])
            ->assertCreated();

        Notification::assertNothingSent();
        $this->assertSame('link', $response->json('credential.type'));
    }

    // -- provisioning: direct --------------------------------------------------

    public function test_direct_provisioning_creates_the_account_with_a_temporary_password(): void
    {
        Notification::fake();
        config()->set('kinetix.membership.provisioning', 'direct');
        config()->set('kinetix.membership.delivery', 'manual');
        config()->set('kinetix.membership.identifier', 'username');

        $response = $this->actingAs($this->manager())
            ->postJson('/_kinetix/members', [
                'username' => 'juan.perez',
                'name'     => 'Juan Pérez',
                'role'     => 'editor',
            ])
            ->assertCreated()
            ->assertJsonFragment(['status' => 'active']);

        Notification::assertNothingSent();

        $this->assertSame('password', $response->json('credential.type'));
        $plain = (string) $response->json('credential.value');
        $this->assertNotSame('', $plain);

        $user = ModeUser::query()->where('username', 'juan.perez')->firstOrFail();

        $this->assertSame('Juan Pérez', $user->name);
        $this->assertNull($user->email, 'an employee without an email address is the whole point');
        $this->assertTrue(Hash::check($plain, $user->password));
        $this->assertTrue($user->hasRole('editor'));

        // The credential is temporary: they must replace it on first use.
        $this->assertTrue(KinetixPasswords::mustChange($user));
    }

    public function test_the_temporary_password_is_never_readable_afterwards(): void
    {
        config()->set('kinetix.membership.provisioning', 'direct');
        config()->set('kinetix.membership.delivery', 'manual');

        $manager = $this->manager();

        $created = $this->actingAs($manager)
            ->postJson('/_kinetix/members', ['email' => 'new@example.com', 'role' => 'editor']);

        $first = (string) $created->json('credential.value');

        // Listing never carries it…
        $this->actingAs($manager)->getJson('/_kinetix/members')
            ->assertOk()
            ->assertJsonMissing(['credential' => ['type' => 'password', 'value' => $first]]);

        // …and asking again REGENERATES rather than reveals.
        $reissued = (string) $this->actingAs($manager)
            ->postJson('/_kinetix/members/'.MemberProvision::first()->getKey().'/credential')
            ->assertOk()
            ->json('credential.value');

        $this->assertNotSame($first, $reissued);

        $user = ModeUser::query()->where('email', 'new@example.com')->firstOrFail();
        $this->assertFalse(Hash::check($first, $user->password), 'the old credential must stop working');
        $this->assertTrue(Hash::check($reissued, $user->password));
    }

    public function test_issuing_a_credential_needs_its_own_ability(): void
    {
        config()->set('kinetix.membership.provisioning', 'direct');

        // A manager who may add members but was not trusted with credentials:
        // handing one over means becoming that person.
        $limited = $this->manager(withCredentials: false);

        $this->actingAs($limited)
            ->postJson('/_kinetix/members', ['email' => 'new@example.com', 'role' => 'editor'])
            ->assertCreated();

        $this->actingAs($limited)
            ->postJson('/_kinetix/members/'.MemberProvision::first()->getKey().'/credential')
            ->assertForbidden();
    }

    public function test_resend_points_a_direct_member_at_the_credential_endpoint(): void
    {
        config()->set('kinetix.membership.provisioning', 'direct');
        config()->set('kinetix.membership.delivery', 'manual');

        $manager = $this->manager();

        $this->actingAs($manager)
            ->postJson('/_kinetix/members', ['email' => 'new@example.com', 'role' => 'editor'])
            ->assertCreated();

        // There is no link to resend — the account already exists.
        $this->actingAs($manager)
            ->postJson('/_kinetix/members/'.MemberProvision::first()->getKey().'/resend')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'This member already has an account; issue a new credential instead.']);
    }

    public function test_a_revoked_member_cannot_be_handed_a_new_credential(): void
    {
        config()->set('kinetix.membership.provisioning', 'direct');

        $manager = $this->manager();

        $this->actingAs($manager)
            ->postJson('/_kinetix/members', ['email' => 'new@example.com', 'role' => 'editor']);

        $provision = MemberProvision::first();

        $this->actingAs($manager)->deleteJson('/_kinetix/members/'.$provision->getKey())->assertOk();

        $this->actingAs($manager)
            ->postJson('/_kinetix/members/'.$provision->getKey().'/credential')
            ->assertStatus(422);
    }

    // -- delivery: sms ---------------------------------------------------------

    public function test_sms_delivery_routes_the_link_to_the_phone(): void
    {
        Notification::fake();
        config()->set('kinetix.membership.delivery', 'sms');
        config()->set('kinetix.membership.identifier', 'phone');
        config()->set('kinetix.membership.sms_channel', 'testsms');
        config()->set('kinetix.membership.activation_notification', SmsActivation::class);
        config()->set('kinetix.credentials.identity.phone_country', 'MX');

        $response = $this->actingAs($this->manager())
            ->postJson('/_kinetix/members', ['phone' => '55 1234 5678', 'role' => 'editor'])
            ->assertCreated();

        // Sent, so nothing is handed back.
        $this->assertNull($response->json('credential'));

        Notification::assertSentOnDemand(
            SmsActivation::class,
            static function (SmsActivation $notification, array $channels, object $notifiable): bool {
                return $channels                      === ['testsms']
                    && $notifiable->routes['testsms'] === '+525512345678';
            },
        );
    }

    public function test_a_notification_that_cannot_speak_the_channel_hands_the_link_over(): void
    {
        Notification::fake();
        config()->set('kinetix.membership.delivery', 'sms');
        config()->set('kinetix.membership.identifier', 'phone');
        config()->set('kinetix.membership.sms_channel', 'testsms');
        // The default notification only knows `toMail()`. Laravel would resolve
        // `toTestsms()` at SEND time and throw inside a queued job, where nobody
        // sees it and the member never gets their link.
        config()->set('kinetix.membership.activation_notification', null);

        $response = $this->actingAs($this->manager())
            ->postJson('/_kinetix/members', ['phone' => '5512345678', 'role' => 'editor'])
            ->assertCreated();

        Notification::assertNothingSent();
        $this->assertSame('link', $response->json('credential.type'));
    }

    public function test_sms_delivery_to_a_member_with_no_phone_hands_the_link_over(): void
    {
        Notification::fake();
        config()->set('kinetix.membership.delivery', 'sms');
        config()->set('kinetix.membership.sms_channel', 'testsms');
        config()->set('kinetix.membership.activation_notification', SmsActivation::class);

        // Provisioned by email, delivered by SMS: there is no number to text.
        $response = $this->actingAs($this->manager())
            ->postJson('/_kinetix/members', ['email' => 'new@example.com', 'role' => 'editor'])
            ->assertCreated();

        Notification::assertNothingSent();
        $this->assertSame('link', $response->json('credential.type'));
    }

    public function test_the_sms_body_carries_the_activation_url(): void
    {
        // Kinetix translations live in the HOST's published `lang/*/kinetix.php`,
        // so the line is registered here to exercise the real interpolation
        // rather than the test environment's missing-key fallback.
        app('translator')->addLines(['kinetix.member_activation_sms' => 'Activate :app: :url'], 'en');

        $provision    = MemberProvision::create(['email' => 'x@example.com', 'role' => 'editor', 'status' => 'pending']);
        $notification = new SmsActivation('https://example.test/activate/abc', $provision, 'testsms');

        $body = $notification->smsContent();

        // The URL is the whole point of the message, and every character before
        // it pushes towards a second billable segment.
        $this->assertStringContainsString('https://example.test/activate/abc', $body);
        $this->assertStringStartsWith('Activate', $body);
        $this->assertSame(['testsms'], $notification->via($provision));
    }

    // -- Identifiers -----------------------------------------------------------

    public function test_a_phone_identifier_is_normalized_before_it_is_stored(): void
    {
        Notification::fake();
        config()->set('kinetix.membership.identifier', 'phone');
        config()->set('kinetix.credentials.identity.phone_country', 'MX');

        $this->actingAs($this->manager())
            ->postJson('/_kinetix/members', ['phone' => '55 1234 5678', 'role' => 'editor'])
            ->assertCreated();

        // Stored canonically, so the same number typed differently cannot
        // become a second provision.
        $this->assertDatabaseHas('kinetix_member_provisions', ['phone' => '+525512345678']);

        $this->actingAs($this->manager())
            ->postJson('/_kinetix/members', ['phone' => '+52 55 1234 5678', 'role' => 'viewer'])
            ->assertCreated();

        $this->assertSame(1, MemberProvision::query()->count());
        $this->assertSame('viewer', MemberProvision::first()->role);
    }

    public function test_an_identifier_the_credentials_module_does_not_accept_falls_back_to_email(): void
    {
        Notification::fake();
        config()->set('kinetix.credentials.identity.fields', ['email']);
        config()->set('kinetix.membership.identifier', 'username');

        // Provisioning someone under an identifier nobody could sign in with
        // would be a directory entry, not an account.
        $this->actingAs($this->manager())
            ->postJson('/_kinetix/members', ['username' => 'juan.perez', 'role' => 'editor'])
            ->assertStatus(422);

        $this->actingAs($this->manager())
            ->postJson('/_kinetix/members', ['email' => 'new@example.com', 'role' => 'editor'])
            ->assertCreated();
    }

    public function test_provisioning_the_same_identifier_twice_looks_identical(): void
    {
        Notification::fake();
        config()->set('kinetix.membership.identifier', 'username');
        config()->set('kinetix.membership.delivery', 'manual');

        $manager = $this->manager();

        $first = $this->actingAs($manager)
            ->postJson('/_kinetix/members', ['username' => 'juan.perez', 'role' => 'editor'])
            ->assertCreated();

        $second = $this->actingAs($manager)
            ->postJson('/_kinetix/members', ['username' => 'juan.perez', 'role' => 'viewer'])
            ->assertCreated();

        // Same shape both times: provisioning must not become a way to test
        // whether somebody is already a member.
        $this->assertSame(
            array_keys($first->json()),
            array_keys($second->json()),
        );
        $this->assertSame(1, MemberProvision::query()->count());
        $this->assertSame(MemberProvisionStatus::Pending, MemberProvision::first()->status);
    }
}
