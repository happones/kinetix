<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\NotificationPreferences\KinetixNotificationPreferences;
use Happones\Kinetix\NotificationPreferences\NotificationPreferenceManager;
use Happones\Kinetix\NotificationPreferences\NotificationTypeRegistry;
use Happones\Kinetix\NotificationRouting\KinetixNotificationRouting;
use Happones\Kinetix\Notifications\KinetixLaravelNotification;
use Happones\Kinetix\Tests\Concerns\CountsKinetixQueries;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MandatoryUser extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class MandatoryClient extends Model
{
    use Notifiable;

    protected $table = 'mandatory_clients';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * A type can declare, in code, channels the user cannot opt out of; the
 * preferences of many users are read in one query.
 */
class NotificationMandatoryChannelsTest extends TestCase
{
    use CountsKinetixQueries;

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.notification_preferences.enabled', true);
        $app['config']->set('kinetix.notification_preferences.channels', ['mail' => 'Email', 'database' => 'In-app', 'broadcast' => 'Push']);
        $app['config']->set('kinetix.notification_preferences.types', ['security' => 'Security alerts', 'marketing' => 'Marketing']);
        $app['config']->set('auth.providers.users.model', MandatoryUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
        Schema::create('mandatory_clients', function (Blueprint $table) {
            $table->increments('id');
        });

        (require __DIR__.'/../../database/migrations/2026_01_01_000012_create_kinetix_notification_preferences_table.php')->up();
        (require __DIR__.'/../../database/migrations/2026_01_01_000039_add_notifiable_type_to_kinetix_notification_preferences_table.php')->up();
        (require __DIR__.'/../../database/migrations/2026_01_01_000040_scope_kinetix_notification_preferences_unique_to_type.php')->up();

        KinetixNotificationRouting::type('security')->mandatory(['mail']);
    }

    private function manager(): NotificationPreferenceManager
    {
        return app(NotificationPreferenceManager::class);
    }

    public function test_a_mandatory_channel_survives_the_users_opt_out(): void
    {
        $user = MandatoryUser::create(['name' => 'Ada']);
        $this->manager()->update($user, 'security', 'mail', false);
        $this->manager()->update($user, 'security', 'database', false);

        $this->assertSame(['mail'], $this->manager()->deliverable($user, 'security', ['mail', 'database']));
        $this->assertTrue($this->manager()->allows($user, 'security', 'mail'));
        $this->assertFalse($this->manager()->allows($user, 'security', 'database'));
    }

    public function test_a_type_without_mandatory_channels_behaves_as_before(): void
    {
        $user = MandatoryUser::create(['name' => 'Ada']);
        $this->manager()->update($user, 'marketing', 'mail', false);

        $this->assertSame(['database'], $this->manager()->deliverable($user, 'marketing', ['mail', 'database']));
        $this->assertSame([], app(NotificationTypeRegistry::class)->mandatoryOf('marketing'));
    }

    public function test_a_typed_notification_still_goes_by_mail_when_the_user_opted_out(): void
    {
        $user = MandatoryUser::create(['name' => 'Ada']);
        $this->manager()->update($user, 'security', 'mail', false);

        $notification = new KinetixLaravelNotification(['title' => 'x'], ['mail', 'database'], 'security');

        $this->assertSame(['mail', 'database'], $notification->via($user));
    }

    public function test_the_matrix_marks_the_mandatory_channels_and_shows_them_on(): void
    {
        $user = MandatoryUser::create(['name' => 'Ada']);
        // An opt-out stored before the type became mandatory.
        $this->manager()->update($user, 'security', 'mail', false);

        $matrix = $this->actingAs($user)->getJson('/_kinetix/notification-preferences')->assertOk()->json('types');

        $security  = collect($matrix)->firstWhere('key', 'security');
        $marketing = collect($matrix)->firstWhere('key', 'marketing');

        $this->assertSame(['mail'], $security['mandatory']);
        $this->assertTrue($security['channels']['mail']);
        $this->assertSame([], $marketing['mandatory']);
    }

    public function test_turning_a_mandatory_channel_off_is_a_422(): void
    {
        $user = MandatoryUser::create(['name' => 'Ada']);

        $this->actingAs($user)
            ->postJson('/_kinetix/notification-preferences', ['type' => 'security', 'channel' => 'mail', 'enabled' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('enabled');

        $this->assertTrue($this->manager()->allows($user, 'security', 'mail'));

        // The mandatory channel can be switched "on"; the others stay free.
        $this->actingAs($user)->postJson('/_kinetix/notification-preferences', ['type' => 'security', 'channel' => 'mail', 'enabled' => true])->assertOk();
        $this->actingAs($user)->postJson('/_kinetix/notification-preferences', ['type' => 'security', 'channel' => 'database', 'enabled' => false])->assertOk();
        $this->assertFalse($this->manager()->allows($user, 'security', 'database'));
    }

    public function test_stored_many_reads_every_users_choices_in_one_query(): void
    {
        $users  = collect(range(1, 8))->map(fn (int $i): MandatoryUser => MandatoryUser::create(['name' => "U{$i}"]));
        $client = MandatoryClient::create();

        $this->manager()->update($users[0], 'marketing', 'mail', false);
        $this->manager()->update($users[3], 'marketing', 'database', false);
        $this->manager()->update($client, 'marketing', 'broadcast', false);

        $stored  = [];
        $queries = $this->kinetixQueriesDuring(function () use ($users, $client, &$stored): void {
            $stored = $this->manager()->storedMany($users->concat([$client]));
        });

        $this->assertCount(1, $this->queriesMatching($queries, '/kinetix_notification_preferences/'), $this->describeQueries($queries));
        $this->assertSame(['marketing' => ['mail' => false]], $stored[NotificationPreferenceManager::ownerKey($users[0])]);
        $this->assertSame(['marketing' => ['database' => false]], $stored[NotificationPreferenceManager::ownerKey($users[3])]);
        $this->assertSame(['marketing' => ['broadcast' => false]], $stored[NotificationPreferenceManager::ownerKey($client)]);
        $this->assertArrayNotHasKey(NotificationPreferenceManager::ownerKey($users[1]), $stored);
    }

    public function test_stored_many_keeps_a_client_apart_from_a_user_with_the_same_id(): void
    {
        $user   = MandatoryUser::create(['name' => 'Ada']);
        $client = MandatoryClient::create();
        $this->assertSame($user->getKey(), $client->getKey());

        $this->manager()->update($client, 'marketing', 'mail', false);

        $stored = $this->manager()->storedMany(collect([$user, $client]));

        $this->assertArrayNotHasKey(NotificationPreferenceManager::ownerKey($user), $stored);
        $this->assertArrayHasKey(NotificationPreferenceManager::ownerKey($client), $stored);
    }

    public function test_deliverable_many_applies_each_users_choices_and_the_mandatory_channels(): void
    {
        $ada = MandatoryUser::create(['name' => 'Ada']);
        $bob = MandatoryUser::create(['name' => 'Bob']);

        $this->manager()->update($ada, 'security', 'database', false);
        $this->manager()->update($bob, 'security', 'mail', false);

        $adaKey = NotificationPreferenceManager::ownerKey($ada);
        $bobKey = NotificationPreferenceManager::ownerKey($bob);

        $result = $this->manager()->deliverableMany(collect([$ada, $bob]), 'security', [
            $adaKey => ['mail', 'database'],
            $bobKey => ['mail', 'database'],
        ]);

        $this->assertSame(['mail'], $result[$adaKey]);
        $this->assertSame(['mail', 'database'], $result[$bobKey]);
    }

    public function test_deliverable_many_is_a_pass_through_with_the_module_off(): void
    {
        config(['kinetix.notification_preferences.enabled' => false]);
        $user = MandatoryUser::create(['name' => 'Ada']);
        DB::table('kinetix_notification_preferences')->insert(['user_id' => $user->getKey(), 'preferences' => json_encode(['marketing' => ['mail' => false]])]);

        $key = NotificationPreferenceManager::ownerKey($user);

        $this->assertSame([$key => ['mail']], $this->manager()->deliverableMany(collect([$user]), 'marketing', [$key => ['mail']]));
    }

    public function test_a_type_registered_later_keeps_its_declaration(): void
    {
        KinetixNotificationPreferences::types(['billing' => 'Billing']);
        KinetixNotificationRouting::type('billing')->requires('billing.manage')->mandatory(['mail', 'mail']);

        $registry = KinetixNotificationPreferences::registry();

        $this->assertSame(['mail'], $registry->mandatoryOf('billing'));
        $this->assertSame('billing.manage', $registry->requirementOf('billing'));
        $this->assertNull($registry->requirementOf('security'));
    }
}
