<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\NotificationRouting\AudienceType;
use Happones\Kinetix\NotificationRouting\KinetixNotificationRouting;
use Happones\Kinetix\NotificationRouting\NotificationGroup;
use Happones\Kinetix\NotificationRouting\NotificationRoute;
use Happones\Kinetix\NotificationRouting\RecipientPlan;
use Happones\Kinetix\NotificationRouting\RoutingPeople;
use Happones\Kinetix\Support\Memo;
use Happones\Kinetix\Tests\Concerns\BuildsRoutingFixture;
use Happones\Kinetix\Tests\Concerns\CountsKinetixQueries;
use Happones\Kinetix\Tests\Fakes\RoutedAlert;
use Happones\Kinetix\Tests\Fakes\RoutingUser;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionServiceProvider;

/** A cache store that cannot lock. */
class NoLockStore implements Store
{
    public function get($key): mixed
    {
        return null;
    }

    public function many(array $keys): array
    {
        return array_fill_keys($keys, null);
    }

    public function put($key, $value, $seconds): bool
    {
        return true;
    }

    public function putMany(array $values, $seconds): bool
    {
        return true;
    }

    public function increment($key, $value = 1): int|bool
    {
        return $value;
    }

    public function decrement($key, $value = 1): int|bool
    {
        return -$value;
    }

    public function forever($key, $value): bool
    {
        return true;
    }

    public function forget($key): bool
    {
        return true;
    }

    public function touch($key, $seconds): bool
    {
        return true;
    }

    public function flush(): bool
    {
        return true;
    }

    public function getPrefix(): string
    {
        return '';
    }
}

/**
 * An app without teams: the user table is the audience, however big, and
 * nobody is lost to a window of the first rows.
 */
class NotificationRoutingNoTeamsTest extends TestCase
{
    use BuildsRoutingFixture;
    use CountsKinetixQueries;

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
        $app['config']->set('kinetix.teams', false);
        $app['config']->set('kinetix.notification_routing.enabled', true);
        $app['config']->set('kinetix.notification_preferences.enabled', true);
        $app['config']->set('kinetix.notification_preferences.channels', ['mail' => 'Email', 'database' => 'In-app']);
        $app['config']->set('kinetix.notification_preferences.types', ['inventory.low-stock' => 'Low stock']);
        $app['config']->set('auth.providers.users.model', RoutingUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createRoutingTables();

        DB::table('users')->insert(array_map(static fn (int $i): array => ['name' => sprintf('User %04d', $i)], range(1, 700)));
    }

    private function user(int $id): RoutingUser
    {
        return RoutingUser::query()->findOrFail($id);
    }

    private function manager(): RoutingUser
    {
        $user = $this->user(1);
        $user->givePermissionTo('notification_routing.manage');

        return $user;
    }

    public function test_members_roles_and_groups_past_the_first_five_hundred_users_are_reached(): void
    {
        NotificationFacade::fake();

        $member  = $this->user(650);
        $holder  = $this->user(600);
        $grouped = $this->user(699);
        $role    = $this->role('stocker');
        $holder->assignRole($role);

        $group = NotificationGroup::query()->create(['name' => 'Leads']);
        $group->members()->create(['user_type' => $grouped->getMorphClass(), 'user_id' => $grouped->getKey()]);

        foreach ([[AudienceType::Member, $this->ref($member)], [AudienceType::Role, $role->getKey()], [AudienceType::Group, $group->getKey()]] as [$audience, $id]) {
            NotificationRoute::query()->create(['type' => 'inventory.low-stock', 'audience_type' => $audience, 'audience_id' => (string) $id, 'channels' => ['mail']]);
        }

        KinetixNotificationRouting::for('inventory.low-stock')->notifyNow(new RoutedAlert);

        foreach ([$member, $holder, $grouped] as $user) {
            NotificationFacade::assertSentTo($user, RoutedAlert::class);
        }

        NotificationFacade::assertSentToTimes($member, RoutedAlert::class, 1);
        NotificationFacade::assertNotSentTo($this->user(2), RoutedAlert::class);
    }

    public function test_the_eligible_audience_is_read_in_chunks_and_stops_at_the_cap(): void
    {
        config(['kinetix.notification_routing.max_recipients' => 3]);

        NotificationRoute::query()->create(['type' => 'inventory.low-stock', 'audience_type' => AudienceType::Eligible, 'audience_id' => '', 'channels' => ['mail']]);

        $queries = $this->kinetixQueriesDuring(function (): void {
            $plan = KinetixNotificationRouting::for('inventory.low-stock')->recipients();

            $this->assertCount(3, $plan->recipients);
            $this->assertTrue($plan->truncated);
        });

        // One read of the first chunk, not 700 users.
        $this->assertLessThanOrEqual(8, count($queries), $this->describeQueries($queries));
    }

    public function test_the_member_picker_is_one_searchable_page(): void
    {
        $this->actingAs($this->manager());

        $all = $this->getJson('/_kinetix/notification-routing/audiences')->assertOk()->json('members');
        $this->assertCount(200, $all);
        $this->assertFalse($this->getJson('/_kinetix/notification-routing/audiences')->json('membersComplete'));
        $this->assertSame(RoutingUser::class.'|2', $all[1]['id']);

        $found = $this->getJson('/_kinetix/notification-routing/audiences?search=User%200650')->json('members');
        $this->assertSame(['User 0650'], array_column($found, 'name'));
        $this->assertTrue($this->getJson('/_kinetix/notification-routing/audiences?search=User%200650')->json('membersComplete'));
    }

    public function test_a_member_far_down_the_table_can_be_routed_and_labelled(): void
    {
        $this->actingAs($this->manager());
        $ref = $this->ref($this->user(690));

        $this->postJson('/_kinetix/notification-routing/routes', ['type' => 'inventory.low-stock', 'audience_type' => 'member', 'audience_id' => $ref, 'channels' => ['mail']])->assertCreated()
            ->assertJsonPath('route.audienceLabel', 'User 0690');

        $this->postJson('/_kinetix/notification-routing/routes', ['type' => 'inventory.low-stock', 'audience_type' => 'member', 'audience_id' => RoutingUser::class.'|99999', 'channels' => ['mail']])->assertNotFound();
        $this->postJson('/_kinetix/notification-routing/routes', ['type' => 'inventory.low-stock', 'audience_type' => 'member', 'audience_id' => 'App\\Nope|1', 'channels' => ['mail']])->assertNotFound();
    }

    public function test_writing_the_same_rule_or_group_twice_keeps_one_row_without_teams(): void
    {
        $this->actingAs($this->manager());
        $body = ['type' => 'inventory.low-stock', 'audience_type' => 'eligible', 'channels' => ['mail']];

        $this->postJson('/_kinetix/notification-routing/routes', $body)->assertCreated();
        $this->postJson('/_kinetix/notification-routing/routes', [...$body, 'channels' => ['database']])->assertOk();
        $this->assertSame(1, NotificationRoute::query()->count());

        $this->postJson('/_kinetix/notification-routing/groups', ['name' => 'Leads'])->assertCreated();
        $this->postJson('/_kinetix/notification-routing/groups', ['name' => 'Leads'])->assertStatus(422);
        $this->assertSame(1, NotificationGroup::query()->count());
    }

    public function test_the_same_rules_cost_the_same_queries_with_many_users(): void
    {
        NotificationFacade::fake();
        $role = $this->role('stocker');

        foreach (range(10, 400) as $id) {
            $this->user($id)->assignRole($role);
        }

        NotificationRoute::query()->create(['type' => 'inventory.low-stock', 'audience_type' => AudienceType::Role, 'audience_id' => (string) $role->getKey(), 'channels' => ['mail']]);

        Memo::flush();
        $queries = $this->kinetixQueriesDuring(function (): void {
            $this->assertCount(391, KinetixNotificationRouting::for('inventory.low-stock')->recipients()->recipients);
        });

        $this->assertLessThanOrEqual(8, count($queries), $this->describeQueries($queries));
    }

    public function test_a_rare_required_ability_keeps_only_a_sample_of_the_people_it_rules_out(): void
    {
        DB::table('users')->insert(array_map(static fn (int $i): array => ['name' => sprintf('Extra %04d', $i)], range(1, 2300)));
        Permission::findOrCreate('stock.view', 'web');
        $this->user(2500)->givePermissionTo('stock.view');
        KinetixNotificationRouting::type('inventory.low-stock')->requires('stock.view');
        NotificationRoute::query()->create(['type' => 'inventory.low-stock', 'audience_type' => AudienceType::Eligible, 'audience_id' => '', 'channels' => ['mail']]);

        $plan = KinetixNotificationRouting::for('inventory.low-stock')->recipients();

        $this->assertCount(1, $plan->recipients);
        $this->assertCount(RecipientPlan::SKIPPED_SAMPLE, $plan->skipped);
        $this->assertSame(2999, $plan->skippedCount);

        $this->actingAs($this->manager());
        $response = $this->getJson('/_kinetix/notification-routing/recipients?type=inventory.low-stock')->assertOk();
        $this->assertCount(50, $response->json('skipped'));
        $this->assertSame(2999, $response->json('skippedCount'));
    }

    public function test_a_key_that_cannot_exist_is_a_404_not_a_database_error(): void
    {
        $this->actingAs($this->manager());
        $group = $this->postJson('/_kinetix/notification-routing/groups', ['name' => 'Leads'])->json('group.id');

        $this->postJson('/_kinetix/notification-routing/routes', ['type' => 'inventory.low-stock', 'audience_type' => 'member', 'audience_id' => RoutingUser::class.'|abc', 'channels' => ['mail']])->assertNotFound();
        $this->postJson('/_kinetix/notification-routing/routes', ['type' => 'inventory.low-stock', 'audience_type' => 'group', 'audience_id' => 'abc', 'channels' => ['mail']])->assertNotFound();
        $this->deleteJson('/_kinetix/notification-routing/groups/'.$group.'/members/'.rawurlencode(RoutingUser::class.'|abc'))->assertOk();
        $this->putJson('/_kinetix/notification-routing/groups/abc', ['name' => 'x'])->assertNotFound();
        $this->deleteJson('/_kinetix/notification-routing/routes/abc')->assertNotFound();
        $this->assertSame([], RoutingPeople::for(null)->find([RoutingUser::class.'|abc', 'nonsense']));
    }

    public function test_the_member_search_is_limited_and_survives_a_name_that_is_not_a_column(): void
    {
        $this->actingAs($this->manager());

        $this->getJson('/_kinetix/notification-routing/audiences?search='.str_repeat('a', 101))->assertStatus(422);

        // Wildcard characters in the term mean themselves.
        $this->assertSame([], $this->getJson('/_kinetix/notification-routing/audiences?search=%25')->json('members'));

        config(['kinetix.notification_routing.name_attribute' => 'display_name']);
        $this->getJson('/_kinetix/notification-routing/audiences?search=zzz')->assertOk();
    }

    public function test_a_cache_store_without_locks_still_writes(): void
    {
        Cache::extend('nolock', fn (): Repository => new Repository(new NoLockStore));
        config(['cache.stores.nolock' => ['driver' => 'nolock'], 'cache.default' => 'nolock']);
        Cache::purge('nolock');

        $this->assertNotInstanceOf(LockProvider::class, Cache::getStore());

        $this->actingAs($this->manager());
        $body = ['type' => 'inventory.low-stock', 'audience_type' => 'eligible', 'channels' => ['mail']];

        $this->postJson('/_kinetix/notification-routing/routes', $body)->assertCreated();
        $this->postJson('/_kinetix/notification-routing/groups', ['name' => 'Leads'])->assertCreated();
    }
}
