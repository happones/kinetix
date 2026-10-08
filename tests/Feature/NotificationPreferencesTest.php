<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\NotificationPreferences\KinetixNotificationPreferences;
use Happones\Kinetix\NotificationPreferences\NotificationPreference;
use Happones\Kinetix\NotificationPreferences\NotificationPreferenceManager;
use Happones\Kinetix\Notifications\KinetixLaravelNotification;
use Happones\Kinetix\Notifications\Notification;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Schema;

class NotifPrefUser extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

/** A second notifiable model whose ids overlap the users'. */
class NotifPrefClient extends Model
{
    use Notifiable;

    protected $table = 'notif_pref_clients';

    public $timestamps = false;

    protected $guarded = [];
}

class NotificationPreferencesTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.notification_preferences.enabled', true);
        $app['config']->set('kinetix.notification_preferences.channels', [
            'mail' => 'Email', 'database' => 'In-app',
        ]);
        $app['config']->set('kinetix.notification_preferences.types', [
            'orders' => 'Order updates', 'marketing' => 'Marketing',
        ]);
        $app['config']->set('auth.providers.users.model', NotifPrefUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
        Schema::create('kinetix_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->json('preferences')->nullable();
            $table->timestamps();
        });
    }

    private function user(): NotifPrefUser
    {
        return NotifPrefUser::create(['name' => 'Ada']);
    }

    public function test_index_returns_the_matrix_defaulting_to_enabled(): void
    {
        $this->actingAs($this->user())
            ->getJson('/_kinetix/notification-preferences')
            ->assertOk()
            ->assertJsonPath('channels.0.key', 'mail')
            ->assertJsonPath('types.0.key', 'orders')
            ->assertJsonPath('types.0.channels.mail', true)
            ->assertJsonPath('types.0.channels.database', true);
    }

    public function test_update_persists_an_opt_out(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/_kinetix/notification-preferences', [
                'type' => 'marketing', 'channel' => 'mail', 'enabled' => false,
            ])
            ->assertOk();

        $this->assertFalse(
            app(NotificationPreferenceManager::class)->allows($user, 'marketing', 'mail'),
        );
        // Other cells stay enabled.
        $this->assertTrue(
            app(NotificationPreferenceManager::class)->allows($user, 'marketing', 'database'),
        );
    }

    public function test_update_rejects_unknown_type_or_channel(): void
    {
        $this->actingAs($this->user())
            ->postJson('/_kinetix/notification-preferences', [
                'type' => 'nope', 'channel' => 'mail', 'enabled' => false,
            ])
            ->assertStatus(422);

        $this->actingAs($this->user())
            ->postJson('/_kinetix/notification-preferences', [
                'type' => 'orders', 'channel' => 'sms', 'enabled' => false,
            ])
            ->assertStatus(422);
    }

    public function test_channels_for_filters_by_preference(): void
    {
        $user    = $this->user();
        $manager = app(NotificationPreferenceManager::class);
        $manager->update($user, 'orders', 'mail', false);

        $this->assertSame(
            ['database'],
            KinetixNotificationPreferences::channelsFor($user, 'orders', ['mail', 'database']),
        );
    }

    private function migrateTypeColumn(): void
    {
        (require __DIR__.'/../../database/migrations/2026_01_01_000039_add_notifiable_type_to_kinetix_notification_preferences_table.php')->up();
        NotificationPreference::flushOwnerTypeColumn();
    }

    /**
     * The matrix used to be advisory: a user who turned a channel off kept
     * receiving it unless the host gated every via() by hand.
     */
    public function test_a_typed_notification_skips_the_channels_the_user_turned_off(): void
    {
        NotificationFacade::fake();
        $user = $this->user();
        app(NotificationPreferenceManager::class)->update($user, 'orders', 'database', false);

        Notification::make()->type('orders')->title('Shipped')->broadcast($user);

        NotificationFacade::assertSentTo(
            $user,
            KinetixLaravelNotification::class,
            static fn (KinetixLaravelNotification $notification, array $channels): bool => $channels === ['broadcast'],
        );
    }

    public function test_a_notification_with_every_channel_turned_off_is_not_sent(): void
    {
        NotificationFacade::fake();
        $user = $this->user();
        app(NotificationPreferenceManager::class)->update($user, 'orders', 'database', false);

        Notification::make()->type('orders')->title('Shipped')->sendToDatabase($user);

        NotificationFacade::assertNothingSentTo($user);
    }

    public function test_untyped_and_unregistered_types_always_deliver(): void
    {
        NotificationFacade::fake();
        $user    = $this->user();
        $manager = app(NotificationPreferenceManager::class);
        $manager->update($user, 'orders', 'database', false);
        // An opt-out stored for a type the matrix no longer shows: the user
        // has no switch left to turn it back on.
        $manager->update($user, 'retired', 'database', false);

        Notification::make()->title('Untyped')->sendToDatabase($user);
        Notification::make()->type('retired')->title('Retired type')->sendToDatabase($user);

        NotificationFacade::assertSentToTimes($user, KinetixLaravelNotification::class, 2);
    }

    public function test_with_the_module_off_preferences_are_not_read(): void
    {
        NotificationFacade::fake();
        config()->set('kinetix.notification_preferences.enabled', false);
        Schema::drop('kinetix_notification_preferences');
        $user = $this->user();

        Notification::make()->type('orders')->title('Shipped')->sendToDatabase($user);

        NotificationFacade::assertSentTo($user, KinetixLaravelNotification::class);
    }

    /**
     * Rows were keyed by id alone, so a Client #1 read and wrote a User #1's
     * choices — and with sends now gated, would have lost their notifications.
     */
    public function test_preferences_belong_to_one_model_type(): void
    {
        $this->migrateTypeColumn();
        Schema::create('notif_pref_clients', function (Blueprint $table) {
            $table->increments('id');
        });

        $user    = $this->user();
        $client  = NotifPrefClient::create(['id' => $user->id]);
        $manager = app(NotificationPreferenceManager::class);

        $manager->update($client, 'orders', 'database', false);

        $this->assertFalse($manager->allows($client, 'orders', 'database'));
        $this->assertTrue($manager->allows($user, 'orders', 'database'));
        $this->assertSame(1, NotificationPreference::query()->count());

        $manager->update($user, 'orders', 'mail', false);

        $this->assertSame(2, NotificationPreference::query()->count());
        $this->assertTrue($manager->allows($client, 'orders', 'mail'));
    }

    public function test_a_row_from_before_the_type_column_stays_with_the_default_user_model(): void
    {
        config()->set('kinetix.membership.user_model', NotifPrefUser::class);
        $user = $this->user();
        DB::table('kinetix_notification_preferences')->insert([
            'user_id'     => $user->id,
            'preferences' => json_encode(['orders' => ['mail' => false]]),
        ]);
        $this->migrateTypeColumn();
        $this->migrateTypeColumn(); // idempotent

        $manager = app(NotificationPreferenceManager::class);
        $this->assertFalse($manager->allows($user, 'orders', 'mail'));

        // The next write claims the row instead of starting a second one.
        $manager->update($user, 'orders', 'database', false);

        $row = NotificationPreference::query()->sole();
        $this->assertSame($user->getMorphClass(), $row->notifiable_type);
        $this->assertSame(['orders' => ['mail' => false, 'database' => false]], $row->preferences);
    }

    public function test_doctor_reports_the_missing_type_column_and_the_registered_types(): void
    {
        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('until its type column exists')
            ->expectsOutputToContain('2 type(s) registered');
    }
}
