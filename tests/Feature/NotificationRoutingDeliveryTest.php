<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\NotificationPreferences\NotificationPreferenceManager;
use Happones\Kinetix\NotificationPreferences\NotificationTypeRegistry;
use Happones\Kinetix\NotificationRouting\AudienceResolver;
use Happones\Kinetix\NotificationRouting\AudienceType;
use Happones\Kinetix\NotificationRouting\Events\AudienceResolved;
use Happones\Kinetix\NotificationRouting\Events\RoutedNotificationSent;
use Happones\Kinetix\NotificationRouting\Jobs\DeliverRouted;
use Happones\Kinetix\NotificationRouting\Jobs\ResolveAudience;
use Happones\Kinetix\NotificationRouting\KinetixNotificationRouting;
use Happones\Kinetix\NotificationRouting\PendingRouting;
use Happones\Kinetix\NotificationRouting\SkipReason;
use Happones\Kinetix\NotificationRouting\TeamStampedDatabaseChannel;
use Happones\Kinetix\Notifications\Notification;
use Happones\Kinetix\Notifications\NotificationScope;
use Happones\Kinetix\Support\Memo;
use Happones\Kinetix\Tests\Concerns\BuildsRoutingFixture;
use Happones\Kinetix\Tests\Concerns\CountsKinetixQueries;
use Happones\Kinetix\Tests\Fakes\RoutedAlert;
use Happones\Kinetix\Tests\Fakes\RoutedDatabaseOnlyAlert;
use Happones\Kinetix\Tests\Fakes\RoutingTeam;
use Happones\Kinetix\Tests\Fakes\RoutingUser;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionServiceProvider;

/**
 * The queued fan-out: who a routed notification reaches, on which channels,
 * and what keeps one team's rules, people and bell away from another's.
 */
class NotificationRoutingDeliveryTest extends TestCase
{
    use BuildsRoutingFixture;
    use CountsKinetixQueries;

    private RoutingTeam $alpha;

    private RoutingTeam $beta;

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
        $app['config']->set('mail.default', 'array');
        $app['config']->set('kinetix.teams', true);
        $app['config']->set('kinetix.notification_routing.enabled', true);
        $app['config']->set('kinetix.notification_preferences.enabled', true);
        $app['config']->set('kinetix.notification_preferences.channels', ['mail' => 'Email', 'database' => 'In-app', 'broadcast' => 'Push']);
        $app['config']->set('kinetix.notification_preferences.types', [
            'inventory.low-stock' => 'Low stock',
            'billing.invoice'     => 'Invoices',
        ]);
        $app['config']->set('auth.providers.users.model', RoutingUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        RoutingUser::$activeTeam = null;
        RoutedAlert::$viaCalls   = 0;

        $this->createRoutingTables();

        $this->alpha = $this->team('alpha');
        $this->beta  = $this->team('beta');
    }

    protected function tearDown(): void
    {
        RoutingUser::$activeTeam = null;

        parent::tearDown();
    }

    private function routing(?RoutingTeam $team = null): PendingRouting
    {
        return KinetixNotificationRouting::for('inventory.low-stock', ($team ?? $this->alpha)->getKey());
    }

    /**
     * @return list<string>
     */
    private function channelsOf(RoutingUser $user, string $notification = RoutedAlert::class): array
    {
        $channels = [];

        NotificationFacade::assertSentTo($user, $notification, function ($sent, array $via) use (&$channels): bool {
            $channels = $via;

            return true;
        });

        // The database channel is always the team-stamping one.
        $channels = array_map(static fn (string $channel): string => $channel === TeamStampedDatabaseChannel::class ? 'database' : $channel, $channels);
        sort($channels);

        return $channels;
    }

    public function test_a_person_several_rules_reach_gets_one_notification_on_the_union_of_their_channels(): void
    {
        NotificationFacade::fake();

        $ada  = $this->member($this->alpha, 'Ada');
        $role = $this->role('stocker');
        $ada->assignRole($role);

        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Member, $ada->getKey(), ['mail']);
        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Role, $role->getKey(), ['database']);
        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Group, $this->group($this->alpha, 'Leads', [$ada])->getKey(), ['broadcast']);
        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Eligible, null, ['mail']);

        $this->routing()->notifyNow(new RoutedAlert);

        NotificationFacade::assertSentToTimes($ada, RoutedAlert::class, 1);
        $this->assertSame(['broadcast', 'database', 'mail'], $this->channelsOf($ada));
    }

    public function test_roles_and_groups_never_reach_past_the_teams_current_members(): void
    {
        NotificationFacade::fake();

        $inside  = $this->member($this->alpha, 'Inside');
        $outside = $this->member($this->beta, 'Outside');
        $left    = $this->member($this->beta, 'Left');
        $role    = $this->role('stocker');

        foreach ([$inside, $outside, $left] as $user) {
            $user->assignRole($role);
        }

        // A stale row: this person was in the group, then left the team.
        $group = $this->group($this->alpha, 'Leads', [$inside, $left]);

        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Role, $role->getKey(), ['mail']);
        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Group, $group->getKey(), ['mail']);
        // Another team's group id used in this team's rule resolves to nobody.
        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Group, $this->group($this->beta, 'Theirs', [$outside])->getKey(), ['mail']);

        $this->routing()->notifyNow(new RoutedAlert);

        NotificationFacade::assertSentTo($inside, RoutedAlert::class);
        NotificationFacade::assertNotSentTo($outside, RoutedAlert::class);
        NotificationFacade::assertNotSentTo($left, RoutedAlert::class);
    }

    public function test_rules_of_another_team_never_apply(): void
    {
        NotificationFacade::fake();

        $ada = $this->member($this->alpha, 'Ada');
        $bob = $this->member($this->beta, 'Bob');

        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Eligible, null, ['mail']);
        $this->route($this->beta, 'inventory.low-stock', AudienceType::Member, $ada->getKey(), ['mail']);

        $this->routing($this->beta)->notifyNow(new RoutedAlert);

        // Beta's only rule names Ada, who is not on beta: nobody is reached.
        NotificationFacade::assertNothingSent();
        $this->assertSame([], $this->routing($this->beta)->recipients()->recipients);

        $this->routing($this->alpha)->notifyNow(new RoutedAlert);
        NotificationFacade::assertSentTo($ada, RoutedAlert::class);
        NotificationFacade::assertNotSentTo($bob, RoutedAlert::class);
    }

    public function test_whoever_lacks_the_required_ability_is_left_out_even_when_named(): void
    {
        NotificationFacade::fake();
        Permission::findOrCreate('stock.view', 'web');

        $allowed = $this->member($this->alpha, 'Allowed');
        $named   = $this->member($this->alpha, 'Named');
        $allowed->givePermissionTo('stock.view');

        KinetixNotificationRouting::type('inventory.low-stock')->requires('stock.view');

        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Eligible, null, ['mail']);
        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Member, $named->getKey(), ['mail']);

        $this->routing()->notifyNow(new RoutedAlert);

        NotificationFacade::assertSentTo($allowed, RoutedAlert::class);
        NotificationFacade::assertNotSentTo($named, RoutedAlert::class);

        $plan = $this->routing()->recipients(new RoutedAlert);
        $this->assertSame([SkipReason::Ineligible], array_map(static fn ($skipped): SkipReason => $skipped->reason, $plan->skipped));
    }

    public function test_an_empty_channel_set_never_falls_back_to_the_notifications_own_channels(): void
    {
        $ada     = $this->member($this->alpha, 'Ada');
        $manager = app(NotificationPreferenceManager::class);
        $manager->update($ada, 'inventory.low-stock', 'mail', false);
        $manager->update($ada, 'inventory.low-stock', 'database', false);

        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Member, $ada->getKey(), ['mail', 'database']);

        $this->routing()->notifyNow(new RoutedAlert);

        $this->assertSame(0, RoutedAlert::$viaCalls);
        $this->assertSame(0, $ada->notifications()->count());
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());

        $skipped = $this->routing()->recipients(new RoutedAlert)->skipped;
        $this->assertSame(SkipReason::OptedOut, $skipped[0]->reason);
    }

    public function test_a_route_with_no_channel_the_type_delivers_on_reaches_nobody(): void
    {
        KinetixNotificationRouting::type('inventory.low-stock');
        app(NotificationTypeRegistry::class)->deliverOn('inventory.low-stock', ['database']);

        $ada = $this->member($this->alpha, 'Ada');
        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Member, $ada->getKey(), ['mail']);

        $plan = $this->routing()->recipients();

        $this->assertSame([], $plan->recipients);
        $this->assertSame(SkipReason::NoChannels, $plan->skipped[0]->reason);
    }

    public function test_a_mandatory_channel_is_delivered_when_the_user_opted_out(): void
    {
        NotificationFacade::fake();
        KinetixNotificationRouting::type('billing.invoice')->mandatory(['mail']);

        $ada = $this->member($this->alpha, 'Ada');
        app(NotificationPreferenceManager::class)->update($ada, 'billing.invoice', 'mail', false);
        app(NotificationPreferenceManager::class)->update($ada, 'billing.invoice', 'database', false);

        $this->route($this->alpha, 'billing.invoice', AudienceType::Member, $ada->getKey(), ['mail', 'database']);

        KinetixNotificationRouting::for('billing.invoice', $this->alpha->getKey())->notifyNow(new RoutedAlert);

        $this->assertSame(['mail'], $this->channelsOf($ada));
    }

    public function test_a_notification_that_cannot_render_a_channel_is_not_sent_on_it(): void
    {
        NotificationFacade::fake();

        $ada = $this->member($this->alpha, 'Ada');
        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Member, $ada->getKey(), ['mail', 'database']);

        $this->routing()->notifyNow(new RoutedDatabaseOnlyAlert);

        $this->assertSame(['database'], $this->channelsOf($ada, RoutedDatabaseOnlyAlert::class));
    }

    public function test_the_bell_of_each_team_shows_only_what_was_routed_there(): void
    {
        $ada = $this->member($this->alpha, 'Ada');
        $ada->teams()->attach($this->beta);

        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Member, $ada->getKey(), ['database']);
        $this->route($this->beta, 'inventory.low-stock', AudienceType::Member, $ada->getKey(), ['database']);

        $this->routing($this->alpha)->notifyNow(new RoutedAlert('From alpha'));
        $this->routing($this->beta)->notifyNow(new RoutedAlert('From beta'));

        $this->actingAs($ada);

        RoutingUser::$activeTeam = $this->alpha->getKey();
        $this->assertSame(['From alpha'], NotificationScope::apply($ada->notifications())->get()->pluck('data.title')->all());

        RoutingUser::$activeTeam = $this->beta->getKey();
        $this->assertSame(['From beta'], NotificationScope::apply($ada->notifications())->get()->pluck('data.title')->all());
    }

    public function test_the_kinetix_builder_is_stamped_with_the_team_and_goes_in_app(): void
    {
        $ada = $this->member($this->alpha, 'Ada');
        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Member, $ada->getKey(), ['database', 'mail']);

        $this->routing()->sendNow(Notification::make()->title('Heads up'));

        $row = $ada->notifications()->sole();

        $this->assertSame('Heads up', $row->data['title']);
        $this->assertEquals($this->alpha->getKey(), $row->data['team']);
    }

    public function test_the_team_is_captured_when_the_notification_is_routed(): void
    {
        Queue::fake();

        $ada = $this->member($this->alpha, 'Ada');
        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Member, $ada->getKey(), ['mail']);

        RoutingUser::$activeTeam = $this->beta->getKey();
        $this->routing()->notify(new RoutedAlert);

        Queue::assertPushed(ResolveAudience::class, fn (ResolveAudience $job): bool => $job->teamKey === $this->alpha->getKey() && $job->type === 'inventory.low-stock');
        Queue::assertNotPushed(DeliverRouted::class);

        // Run the pushed job as a worker would: it fans out to delivery jobs.
        $job = Queue::pushed(ResolveAudience::class)->first();
        $job->handle(app(AudienceResolver::class));

        Queue::assertPushed(DeliverRouted::class, fn (DeliverRouted $deliver): bool => $deliver->teamKey === $this->alpha->getKey() && $deliver->channels === ['mail']);
    }

    public function test_a_large_audience_is_delivered_in_chunks_per_channel_set(): void
    {
        Queue::fake();
        config(['kinetix.notification_routing.chunk' => 2]);

        foreach (range(1, 5) as $i) {
            $this->member($this->alpha, "M{$i}");
        }

        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Eligible, null, ['mail']);

        $this->routing()->notify(new RoutedAlert);
        Queue::pushed(ResolveAudience::class)->first()->handle(app(AudienceResolver::class));

        Queue::assertPushed(DeliverRouted::class, 3);
    }

    public function test_the_jobs_run_on_the_configured_queue(): void
    {
        Queue::fake();
        config(['kinetix.notification_routing.queue' => 'alerts']);

        $this->routing()->notify(new RoutedAlert);

        Queue::assertPushedOn('alerts', ResolveAudience::class);
    }

    public function test_the_fallback_is_used_only_when_the_team_has_no_rule(): void
    {
        NotificationFacade::fake();

        $owner = $this->member($this->alpha, 'Owner');
        $ada   = $this->member($this->alpha, 'Ada');

        $this->routing()->fallback([$owner])->notifyNow(new RoutedAlert);

        $this->assertSame(['broadcast', 'database', 'mail'], $this->channelsOf($owner));
        $this->assertTrue($this->routing()->fallback([$owner])->recipients()->fellBack);

        // A rule exists, even one that reaches nobody: no fallback.
        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Group, $this->group($this->alpha, 'Empty')->getKey(), ['mail']);

        $this->routing()->fallback([$ada])->notifyNow(new RoutedAlert);

        NotificationFacade::assertNotSentTo($ada, RoutedAlert::class);
        $this->assertFalse($this->routing()->fallback([$ada])->recipients()->fellBack);
    }

    public function test_no_rule_and_no_fallback_warns_and_notifies_nobody(): void
    {
        NotificationFacade::fake();
        Log::spy();

        $this->member($this->alpha, 'Ada');

        $this->routing()->notifyNow(new RoutedAlert);

        NotificationFacade::assertNothingSent();
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_with_the_module_off_only_the_fallback_is_notified(): void
    {
        NotificationFacade::fake();
        config(['kinetix.notification_routing.enabled' => false]);

        $owner = $this->member($this->alpha, 'Owner');
        $ada   = $this->member($this->alpha, 'Ada');

        $this->routing()->fallback([$owner])->notifyNow(new RoutedAlert);

        NotificationFacade::assertSentTo($owner, RoutedAlert::class);
        NotificationFacade::assertNotSentTo($ada, RoutedAlert::class);
    }

    public function test_more_people_than_the_cap_are_cut_and_flagged(): void
    {
        config(['kinetix.notification_routing.max_recipients' => 3]);
        Log::spy();

        foreach (range(1, 6) as $i) {
            $this->member($this->alpha, "M{$i}");
        }

        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Eligible, null, ['mail']);

        $plan = $this->routing()->recipients();

        $this->assertCount(3, $plan->recipients);
        $this->assertTrue($plan->truncated);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_hundred_people_cost_the_same_queries_as_a_few(): void
    {
        NotificationFacade::fake();

        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Eligible, null, ['mail', 'database']);
        $role = $this->role('stocker');
        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Role, $role->getKey(), ['broadcast']);

        $measure = function (): int {
            Memo::flush();

            return count($this->kinetixQueriesDuring(function (): void {
                $this->routing()->notifyNow(new RoutedAlert);
            }));
        };

        foreach (range(1, 4) as $i) {
            $this->member($this->alpha, "Few{$i}")->assignRole($role);
        }

        $few = $measure();

        foreach (range(1, 96) as $i) {
            $this->member($this->alpha, "Many{$i}")->assignRole($role);
        }

        $many = $measure();

        $this->assertSame($few, $many);
        $this->assertLessThanOrEqual(12, $many);
    }

    public function test_the_audience_event_carries_counts_and_no_people(): void
    {
        NotificationFacade::fake();
        Event::fake([AudienceResolved::class, RoutedNotificationSent::class]);

        $ada = $this->member($this->alpha, 'Ada');
        $this->member($this->alpha, 'Bob');
        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Eligible, null, ['mail']);
        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Member, $ada->getKey(), ['database']);

        $this->routing()->notifyNow(new RoutedAlert);

        Event::assertDispatched(AudienceResolved::class, fn (AudienceResolved $event): bool => $event->recipients === 2
            && $event->perChannel == ['mail' => 2, 'database' => 1]
            && $event->team === $this->alpha->getKey()
            && $event->hasRules);
        Event::assertDispatched(RoutedNotificationSent::class, 2);
    }

    public function test_routes_changed_in_the_request_are_seen_by_the_next_resolution(): void
    {
        $ada = $this->member($this->alpha, 'Ada');
        $this->assertSame([], $this->routing()->recipients()->recipients);

        $route = $this->route($this->alpha, 'inventory.low-stock', AudienceType::Member, $ada->getKey(), ['mail']);
        $this->assertCount(1, $this->routing()->recipients()->recipients);

        $route->delete();
        $this->assertSame([], $this->routing()->recipients()->recipients);
    }

    public function test_a_role_nobody_in_the_team_holds_reaches_nobody(): void
    {
        $this->member($this->alpha, 'Ada');
        $role = $this->role('ghost');
        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Role, $role->getKey(), ['mail']);

        $this->assertSame([], $this->routing()->recipients()->recipients);
    }
}
