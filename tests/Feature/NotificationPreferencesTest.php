<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\NotificationPreferences\KinetixNotificationPreferences;
use Happones\Kinetix\NotificationPreferences\NotificationPreferenceManager;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;

class NotifPrefUser extends Authenticatable
{
    protected $table = 'users';

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
}
