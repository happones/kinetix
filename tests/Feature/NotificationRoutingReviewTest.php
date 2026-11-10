<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Gdpr\Events\GdprPurged;
use Happones\Kinetix\NotificationRouting\AudienceType;
use Happones\Kinetix\NotificationRouting\Events\NotificationRouteChanged;
use Happones\Kinetix\NotificationRouting\KinetixNotificationRouting;
use Happones\Kinetix\NotificationRouting\NotificationGroupMember;
use Happones\Kinetix\NotificationRouting\NotificationRoute;
use Happones\Kinetix\NotificationRouting\PendingRouting;
use Happones\Kinetix\NotificationRouting\RoutingPeople;
use Happones\Kinetix\Permissions\PermissionTeam;
use Happones\Kinetix\Permissions\TeamOwner;
use Happones\Kinetix\Support\HostKeys;
use Happones\Kinetix\Support\KinetixTeams;
use Happones\Kinetix\Support\Memo;
use Happones\Kinetix\Tests\Concerns\BuildsRoutingFixture;
use Happones\Kinetix\Tests\Concerns\CountsKinetixQueries;
use Happones\Kinetix\Tests\Fakes\RoutedAlert;
use Happones\Kinetix\Tests\Fakes\RoutingBothMembers;
use Happones\Kinetix\Tests\Fakes\RoutingClient;
use Happones\Kinetix\Tests\Fakes\RoutingOwnerRule;
use Happones\Kinetix\Tests\Fakes\RoutingTeam;
use Happones\Kinetix\Tests\Fakes\RoutingUser;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionServiceProvider;

/**
 * What a review of notification routing found: people outside the team,
 * member identity across model types, the cost of the required ability, and
 * writes racing each other.
 */
class NotificationRoutingReviewTest extends TestCase
{
    use BuildsRoutingFixture;
    use CountsKinetixQueries;

    private RoutingTeam $alpha;

    private RoutingTeam $beta;

    private RoutingUser $admin;

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
        $app['config']->set('kinetix.notification_preferences.channels', ['mail' => 'Email', 'database' => 'In-app']);
        $app['config']->set('kinetix.notification_preferences.types', ['inventory.low-stock' => 'Low stock']);
        $app['config']->set('auth.providers.users.model', RoutingUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        RoutingUser::$activeTeam = null;

        $this->createRoutingTables();

        $this->alpha = $this->team('alpha');
        $this->beta  = $this->team('beta');
        $this->admin = $this->admin($this->alpha);
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

    private function url(string $path = '', string $team = 'alpha'): string
    {
        return "/{$team}/_kinetix/notification-routing".($path === '' ? '' : "/{$path}");
    }

    public function test_teams_on_without_a_resolvable_team_reach_nobody(): void
    {
        // The user model has no such relation: the team model cannot be found.
        config(['kinetix.team_switcher.teams_relation' => 'organizations']);

        $this->member($this->alpha, 'InAlpha');
        $this->member($this->beta, 'OnlyInBeta');
        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Eligible, null, ['mail']);

        $this->assertNull(HostKeys::teamModel());
        $this->assertSame([], $this->routing()->recipients()->recipients);
        $this->assertSame([], RoutingPeople::for($this->alpha->getKey())->members());
        $this->assertSame([], RoutingPeople::for(null)->members());

        // The shared resolver stays as the Inbox has always had it, unless asked to fail closed.
        $this->assertCount(3, KinetixTeams::members($this->alpha->getKey()));
        $this->assertCount(0, KinetixTeams::members($this->alpha->getKey(), null, 'members', failClosed: true));

        Artisan::call('kinetix:doctor', ['--json' => true]);
        $errors = collect(json_decode(Artisan::output(), true)['findings'])->where('section', 'Notification routing')->where('level', 'error');

        $this->assertTrue($errors->contains(fn (array $finding): bool => str_contains($finding['message'], 'cannot be resolved') && str_contains((string) $finding['hint'], 'notification_routing.members')));
    }

    public function test_a_host_callable_supplies_the_members_when_the_team_model_cannot(): void
    {
        config(['kinetix.team_switcher.teams_relation' => 'organizations', 'kinetix.notification_routing.members' => [RoutingBothMembers::class, 'of']]);

        $inAlpha = $this->member($this->alpha, 'InAlpha');
        $this->member($this->beta, 'OnlyInBeta');
        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Eligible, null, ['mail']);

        $names = array_map(fn ($recipient): string => $recipient->user instanceof RoutingUser ? $recipient->user->name : 'client', $this->routing()->recipients()->recipients);

        $this->assertSame(['Admin of alpha', 'InAlpha'], $names);
        $this->assertTrue($inAlpha->exists);
    }

    public function test_the_required_ability_costs_the_same_queries_for_a_few_and_for_many(): void
    {
        NotificationFacade::fake();
        Permission::findOrCreate('stock.view', 'web');
        KinetixNotificationRouting::type('inventory.low-stock')->requires('stock.view');
        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Eligible, null, ['mail']);

        $measure = function (): int {
            Memo::flush();

            return count($this->kinetixQueriesDuring(function (): void {
                $this->routing()->recipients();
            }));
        };

        foreach (range(1, 5) as $i) {
            $this->member($this->alpha, "F{$i}")->givePermissionTo('stock.view');
        }

        // The first run warms spatie's own permission cache.
        $measure();
        $few = $measure();

        foreach (range(1, 80) as $i) {
            $this->member($this->alpha, "M{$i}")->givePermissionTo('stock.view');
        }

        $many = $measure();

        $this->assertSame($few, $many, $this->describeQueries($this->kinetixQueries));
        $this->assertLessThanOrEqual(12, $many);
        $this->assertCount(85, $this->routing()->recipients()->recipients);
    }

    public function test_eligibility_follows_the_routing_team_when_spatie_teams_are_off(): void
    {
        config(['permission.teams' => false]);

        $user = $this->member($this->alpha, 'Both');
        $user->teams()->attach($this->beta);

        // The user's own current team is beta; the ability holds only in alpha.
        RoutingUser::$activeTeam = $this->beta->getKey();
        Gate::define('stock.view', fn (): bool => (string) KinetixTeams::currentTeamKey() === (string) $this->alpha->getKey());
        KinetixNotificationRouting::type('inventory.low-stock')->requires('stock.view');
        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Eligible, null, ['mail']);

        $this->assertCount(2, $this->routing($this->alpha)->recipients()->recipients);

        // Routed for beta, the same ability does not hold.
        $this->route($this->beta, 'inventory.low-stock', AudienceType::Eligible, null, ['mail']);
        $this->assertSame([], $this->routing($this->beta)->recipients()->recipients);
        // Outside the resolution nothing changes.
        $this->assertNull(PermissionTeam::pinned());
    }

    public function test_the_owner_bypass_asks_about_the_routing_team_not_the_users_current_team(): void
    {
        config(['kinetix.permissions.owner_bypass' => [RoutingOwnerRule::class, 'owns']]);

        $user = $this->member($this->alpha, 'OwnsBeta');
        $user->teams()->attach($this->beta);
        RoutingUser::$activeTeam = $this->beta->getKey();

        TeamOwner::flush();
        $this->assertTrue(TeamOwner::check($user));

        // Resolved for alpha, the same user does not own the team.
        $this->assertFalse(PermissionTeam::pin($this->alpha->getKey(), fn (): bool => TeamOwner::check($user)));
        $this->assertTrue(TeamOwner::check($user));
    }

    public function test_a_fallback_person_outside_the_team_is_left_out(): void
    {
        NotificationFacade::fake();

        $owner   = $this->member($this->alpha, 'Owner');
        $outside = $this->member($this->beta, 'Outside');

        $this->routing()->fallback([$owner, $outside])->notifyNow(new RoutedAlert);

        NotificationFacade::assertSentTo($owner, RoutedAlert::class);
        NotificationFacade::assertNotSentTo($outside, RoutedAlert::class);
    }

    public function test_a_user_and_a_client_with_the_same_key_are_different_members(): void
    {
        config(['kinetix.notification_routing.members' => [RoutingBothMembers::class, 'of']]);
        NotificationFacade::fake();

        $user = null;

        foreach (range(1, 5) as $i) {
            $user = $this->member($this->alpha, "U{$i}");
        }

        foreach (range(1, $user->getKey()) as $i) {
            $client = RoutingClient::query()->create(['name' => "C{$i}"]);
        }

        $this->assertSame($user->getKey(), $client->getKey());

        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Member, $this->ref($user), ['mail']);
        $this->routing()->notifyNow(new RoutedAlert);

        NotificationFacade::assertSentTo($user, RoutedAlert::class);
        NotificationFacade::assertNotSentTo($client, RoutedAlert::class);

        // The audiences list both, under different ids.
        $ids = array_column($this->actingAs($this->admin)->getJson($this->url('audiences'))->json('members'), 'id');
        $this->assertContains($user->getMorphClass().'|'.$user->getKey(), $ids);
        $this->assertContains($client->getMorphClass().'|'.$client->getKey(), $ids);

        // A client ref is accepted as a rule's member, and is a different rule.
        $this->postJson($this->url('routes'), ['type' => 'inventory.low-stock', 'audience_type' => 'member', 'audience_id' => $client->getMorphClass().'|'.$client->getKey(), 'channels' => ['mail']])->assertCreated();
        $this->assertSame(2, NotificationRoute::query()->count());
    }

    public function test_group_removal_and_account_purge_touch_only_that_models_rows(): void
    {
        config(['kinetix.notification_routing.members' => [RoutingBothMembers::class, 'of']]);

        $user = null;

        foreach (range(1, 5) as $i) {
            $user = $this->member($this->alpha, "U{$i}");
        }

        foreach (range(1, $user->getKey()) as $i) {
            $client = RoutingClient::query()->create(['name' => "C{$i}"]);
        }

        $userRef   = $this->ref($user);
        $clientRef = $client->getMorphClass().'|'.$client->getKey();

        $group = $this->actingAs($this->admin)->postJson($this->url('groups'), ['name' => 'Mixed', 'member_ids' => [$userRef, $clientRef]])->assertCreated();
        $id    = $group->json('group.id');
        $this->assertEqualsCanonicalizing([$userRef, $clientRef], array_column($group->json('group.members'), 'id'));

        // The ref goes through the URL encoded (its backslashes included).
        $this->deleteJson($this->url("groups/{$id}/members/".rawurlencode($userRef)))->assertOk()
            ->assertJsonPath('group.members', [['id' => $clientRef, 'name' => 'C'.$client->getKey()]]);
        $this->assertSame([$client->getMorphClass()], NotificationGroupMember::query()->pluck('user_type')->all());

        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Member, $userRef, ['mail']);
        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Member, $clientRef, ['mail']);

        GdprPurged::dispatch($client);

        $this->assertSame([$userRef], NotificationRoute::query()->pluck('audience_id')->all());
        $this->assertSame(0, NotificationGroupMember::query()->count());

        $this->postJson($this->url("groups/{$id}/members"), ['member_ids' => [$userRef]])->assertOk();
        GdprPurged::dispatch($client);
        $this->assertSame(1, NotificationGroupMember::query()->count());
    }

    public function test_a_second_identical_rule_written_between_the_check_and_the_insert_updates_it(): void
    {
        $ada  = $this->member($this->alpha, 'Ada');
        $body = ['type' => 'inventory.low-stock', 'audience_type' => 'member', 'audience_id' => $this->ref($ada), 'channels' => ['mail', 'database']];

        // Another request wins the race: its row lands just before ours.
        NotificationRoute::creating(function (NotificationRoute $route): void {
            static $once = true;

            if ($once) {
                $once = false;
                DB::table('kinetix_notification_routes')->insert(['team_id' => $route->team_id, 'type' => $route->type, 'audience_type' => $route->audience_type->value, 'audience_id' => $route->audience_id, 'channels' => json_encode(['mail'])]);
            }
        });

        $this->actingAs($this->admin)->postJson($this->url('routes'), $body)->assertOk()->assertJsonPath('route.channels', ['mail', 'database']);

        $this->assertSame(1, NotificationRoute::query()->count());
    }

    public function test_a_role_id_may_be_sent_as_a_number(): void
    {
        $role = $this->role('stocker');

        $this->actingAs($this->admin)->postJson($this->url('routes'), ['type' => 'inventory.low-stock', 'audience_type' => 'role', 'audience_id' => $role->getKey(), 'channels' => ['mail']])
            ->assertCreated()->assertJsonPath('route.audienceLabel', 'stocker');
    }

    public function test_group_member_writes_cost_a_fixed_number_of_queries_and_say_who_changed(): void
    {
        Event::fake([NotificationRouteChanged::class]);

        $refs = [];

        foreach (range(1, 120) as $i) {
            $refs[] = $this->ref($this->member($this->alpha, "M{$i}"));
        }

        $this->actingAs($this->admin);
        $id = $this->postJson($this->url('groups'), ['name' => 'Big', 'member_ids' => array_slice($refs, 0, 20)])->assertCreated()->json('group.id');

        $queries = $this->kinetixQueriesDuring(function () use ($id, $refs): void {
            $this->postJson($this->url("groups/{$id}/members"), ['member_ids' => array_slice($refs, 20, 100)])->assertOk();
        });

        $this->assertLessThanOrEqual(15, count($queries), $this->describeQueries($queries));
        $this->assertSame(120, NotificationGroupMember::query()->count());

        $this->putJson($this->url("groups/{$id}"), ['name' => 'Big', 'member_ids' => array_slice($refs, 0, 2)])->assertOk();

        Event::assertDispatched(NotificationRouteChanged::class, fn (NotificationRouteChanged $event): bool => $event->subject === 'group'
            && $event->action                                                                                                  === 'updated'
            && count($event->after['members'] ?? [])                                                                           === 100);
        Event::assertDispatched(NotificationRouteChanged::class, fn (NotificationRouteChanged $event): bool => $event->subject === 'group'
            && $event->action                                                                                                  === 'updated'
            && count($event->before['members'] ?? [])                                                                          === 118);
    }

    public function test_the_doctor_knows_a_spatie_permission_as_a_required_ability(): void
    {
        Permission::findOrCreate('stock.view', 'web');
        KinetixNotificationRouting::type('inventory.low-stock')->requires('stock.view');

        Artisan::call('kinetix:doctor', ['--json' => true]);
        $items = collect(json_decode(Artisan::output(), true)['findings'])->where('section', 'Notification routing')->pluck('items')->flatten();

        $this->assertFalse($items->contains(fn (string $item): bool => str_contains($item, 'stock.view')));

        KinetixNotificationRouting::type('inventory.low-stock')->requires('stock.nope');

        Artisan::call('kinetix:doctor', ['--json' => true]);
        $items = collect(json_decode(Artisan::output(), true)['findings'])->where('section', 'Notification routing')->pluck('items')->flatten();

        $this->assertTrue($items->contains(fn (string $item): bool => str_contains($item, 'stock.nope')));
    }

    public function test_the_people_who_reach_the_cap_are_cut_in_a_deterministic_order(): void
    {
        config(['kinetix.notification_routing.max_recipients' => 2]);

        foreach (range(1, 5) as $i) {
            $this->member($this->alpha, "M{$i}");
        }

        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Eligible, null, ['mail']);

        $first = array_map(fn ($r): string => RoutingPeople::ref($r->user), $this->routing()->recipients()->recipients);
        Memo::flush();
        $second = array_map(fn ($r): string => RoutingPeople::ref($r->user), $this->routing()->recipients()->recipients);

        $this->assertSame($first, $second);
        $this->assertCount(2, $first);
        $sorted = $first;
        sort($sorted);
        $this->assertSame($sorted, $first);
    }

    public function test_the_member_picker_is_capped_and_says_whether_it_is_complete(): void
    {
        foreach (range(1, 230) as $i) {
            $this->member($this->alpha, sprintf('Pick %03d', $i));
        }

        $this->role('stocker');
        $this->actingAs($this->admin);

        $page = $this->getJson($this->url('audiences'))->assertOk();
        $this->assertCount(200, $page->json('members'));
        $this->assertFalse($page->json('membersComplete'));

        $found = $this->getJson($this->url('audiences?search=Pick 22'))->json();
        $this->assertTrue($found['membersComplete']);
        $this->assertSame(10, count($found['members']));

        $skipped = $this->getJson($this->url('audiences?members=0'))->assertOk();
        $this->assertSame([], $skipped->json('members'));
        $this->assertFalse($skipped->json('membersComplete'));
        $this->assertSame(['stocker'], array_column($skipped->json('roles'), 'name'));
    }
}
