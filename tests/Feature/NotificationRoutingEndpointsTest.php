<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Gdpr\Events\GdprPurged;
use Happones\Kinetix\Gdpr\GdprManager;
use Happones\Kinetix\Inbox\InboxManager;
use Happones\Kinetix\KinetixServiceProvider;
use Happones\Kinetix\NotificationPreferences\KinetixNotificationPreferences;
use Happones\Kinetix\NotificationRouting\AudienceType;
use Happones\Kinetix\NotificationRouting\Events\NotificationRouteChanged;
use Happones\Kinetix\NotificationRouting\KinetixNotificationRouting;
use Happones\Kinetix\NotificationRouting\NotificationGroup;
use Happones\Kinetix\NotificationRouting\NotificationGroupMember;
use Happones\Kinetix\NotificationRouting\NotificationRoute;
use Happones\Kinetix\Support\KinetixTeams;
use Happones\Kinetix\Tests\Concerns\BuildsRoutingFixture;
use Happones\Kinetix\Tests\Fakes\RoutingTeam;
use Happones\Kinetix\Tests\Fakes\RoutingUser;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionServiceProvider;

/** A host's own way to name a team's members. */
class RoutingMembersResolver
{
    /**
     * @return list<RoutingUser>
     */
    public static function of(int|string|null $team): array
    {
        return RoutingUser::query()->where('name', 'like', 'Picked%')->get()->all();
    }
}

/**
 * The admin endpoints of notification routing: authorization, validation and
 * the team boundary every id is held to.
 */
class NotificationRoutingEndpointsTest extends TestCase
{
    use BuildsRoutingFixture;

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
        $app['config']->set('kinetix.teams', true);
        $app['config']->set('kinetix.notification_routing.enabled', true);
        $app['config']->set('kinetix.notification_preferences.enabled', true);
        $app['config']->set('kinetix.notification_preferences.channels', ['mail' => 'Email', 'database' => 'In-app', 'broadcast' => 'Push']);
        $app['config']->set('kinetix.notification_preferences.types', ['inventory.low-stock' => 'Low stock', 'billing.invoice' => 'Invoices']);
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

        KinetixNotificationPreferences::registry()->deliverOn('billing.invoice', ['mail']);
        KinetixNotificationRouting::type('billing.invoice')->requires('billing.manage')->mandatory(['mail']);
    }

    private function url(string $path = '', string $team = 'alpha'): string
    {
        return "/{$team}/_kinetix/notification-routing".($path === '' ? '' : "/{$path}");
    }

    public function test_every_endpoint_needs_the_manage_ability(): void
    {
        $nobody = $this->member($this->alpha, 'Nobody');
        $group  = $this->group($this->alpha, 'Leads');
        $route  = $this->route($this->alpha, 'inventory.low-stock', AudienceType::Eligible, null, ['mail']);

        $this->actingAs($nobody);

        $this->getJson($this->url())->assertForbidden();
        $this->getJson($this->url('audiences'))->assertForbidden();
        $this->getJson($this->url('recipients?type=inventory.low-stock'))->assertForbidden();
        $this->postJson($this->url('routes'), [])->assertForbidden();
        $this->putJson($this->url("routes/{$route->getKey()}"), [])->assertForbidden();
        $this->deleteJson($this->url("routes/{$route->getKey()}"))->assertForbidden();
        $this->getJson($this->url('groups'))->assertForbidden();
        $this->postJson($this->url('groups'), [])->assertForbidden();
        $this->putJson($this->url("groups/{$group->getKey()}"), [])->assertForbidden();
        $this->deleteJson($this->url("groups/{$group->getKey()}"))->assertForbidden();
        $this->postJson($this->url("groups/{$group->getKey()}/members"), [])->assertForbidden();
        $this->deleteJson($this->url("groups/{$group->getKey()}/members/1"))->assertForbidden();

        $this->assertSame(1, NotificationRoute::query()->count());
    }

    public function test_the_preview_is_throttled(): void
    {
        $this->assertContains(
            'throttle:kinetix-notification-routing',
            Route::getRoutes()->getByName('kinetix.notification-routing.recipients')?->gatherMiddleware() ?? [],
        );
    }

    public function test_a_team_you_are_not_in_is_a_404(): void
    {
        $this->actingAs($this->admin)->getJson($this->url('', 'beta'))->assertNotFound();
    }

    public function test_the_index_lists_routable_types_with_their_rules(): void
    {
        $ada = $this->member($this->alpha, 'Ada');
        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Member, $ada->getKey(), ['mail']);
        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Eligible, null, ['database']);
        $this->route($this->beta, 'inventory.low-stock', AudienceType::Eligible, null, ['broadcast']);

        $response = $this->actingAs($this->admin)->getJson($this->url())->assertOk();

        $this->assertSame(['inventory.low-stock', 'billing.invoice'], array_column($response->json('types'), 'key'));
        $this->assertSame([['key' => 'mail', 'label' => 'Email'], ['key' => 'database', 'label' => 'In-app'], ['key' => 'broadcast', 'label' => 'Push']], $response->json('channels'));

        $stock = $response->json('types.0');
        $this->assertSame(['mail', 'database', 'broadcast'], $stock['channels']);
        $this->assertSame([], $stock['mandatory']);
        $this->assertNull($stock['requires']);
        $this->assertSame([
            ['member', $this->ref($ada), 'Ada', ['mail']],
            ['eligible', null, null, ['database']],
        ], array_map(static fn (array $route): array => [$route['audienceType'], $route['audienceId'], $route['audienceLabel'], $route['channels']], $stock['routes']));

        $invoice = $response->json('types.1');
        $this->assertSame(['mail'], $invoice['channels']);
        $this->assertSame(['mail'], $invoice['mandatory']);
        $this->assertSame('billing.manage', $invoice['requires']);
        $this->assertSame([], $invoice['routes']);
    }

    public function test_kinetixs_own_types_are_not_routable(): void
    {
        KinetixNotificationPreferences::types([KinetixNotificationPreferences::EXPORTS => 'Exports']);

        $keys = array_column($this->actingAs($this->admin)->getJson($this->url())->json('types'), 'key');

        $this->assertNotContains(KinetixNotificationPreferences::EXPORTS, $keys);

        $this->postJson($this->url('routes'), ['type' => KinetixNotificationPreferences::EXPORTS, 'audience_type' => 'eligible', 'channels' => ['database']])
            ->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_the_audiences_are_the_teams_members_roles_and_groups(): void
    {
        $ada = $this->member($this->alpha, 'Ada');
        $this->member($this->beta, 'Outsider');
        $this->role('stocker');
        $this->group($this->alpha, 'Leads');
        $this->group($this->beta, 'Theirs');

        $response = $this->actingAs($this->admin)->getJson($this->url('audiences'))->assertOk();

        $this->assertSame(['Ada', 'Admin of alpha'], array_column($response->json('members'), 'name'));
        $this->assertContains($this->ref($ada), array_column($response->json('members'), 'id'));
        $this->assertSame(['stocker'], array_column($response->json('roles'), 'name'));
        $this->assertSame(['Leads'], array_column($response->json('groups'), 'name'));
    }

    public function test_a_rule_is_created_and_then_updated_in_place(): void
    {
        Event::fake([NotificationRouteChanged::class]);
        $ada = $this->member($this->alpha, 'Ada');

        $body = ['type' => 'inventory.low-stock', 'audience_type' => 'member', 'audience_id' => $this->ref($ada), 'channels' => ['mail']];

        $created = $this->actingAs($this->admin)->postJson($this->url('routes'), $body)->assertCreated()
            ->assertJsonPath('route.audienceLabel', 'Ada')
            ->assertJsonPath('route.channels', ['mail']);

        $this->actingAs($this->admin)->postJson($this->url('routes'), [...$body, 'channels' => ['mail', 'database']])->assertOk()
            ->assertJsonPath('route.id', $created->json('route.id'))
            ->assertJsonPath('route.channels', ['mail', 'database']);

        $this->assertSame(1, NotificationRoute::query()->count());
        $this->assertEquals($this->alpha->getKey(), NotificationRoute::query()->sole()->team_id);

        $this->actingAs($this->admin)->putJson($this->url('routes/'.$created->json('route.id')), ['channels' => ['broadcast']])->assertOk()
            ->assertJsonPath('route.channels', ['broadcast']);

        Event::assertDispatched(NotificationRouteChanged::class, fn (NotificationRouteChanged $event): bool => $event->action === 'created' && $event->subject === 'route' && $event->actor === $this->admin->getKey());
        Event::assertDispatched(NotificationRouteChanged::class, fn (NotificationRouteChanged $event): bool => $event->action === 'updated' && $event->before !== null && $event->after !== null);
    }

    public function test_the_eligible_audience_has_no_id_and_is_not_duplicated(): void
    {
        $body = ['type' => 'inventory.low-stock', 'audience_type' => 'eligible', 'channels' => ['mail']];

        $this->actingAs($this->admin)->postJson($this->url('routes'), $body)->assertCreated()->assertJsonPath('route.audienceId', null);
        $this->actingAs($this->admin)->postJson($this->url('routes'), [...$body, 'channels' => ['database']])->assertOk();

        $this->assertSame(1, NotificationRoute::query()->count());
    }

    public function test_a_rule_is_validated_against_the_registry_and_the_types_channels(): void
    {
        $this->actingAs($this->admin);

        $this->postJson($this->url('routes'), ['type' => 'nope', 'audience_type' => 'eligible', 'channels' => ['mail']])->assertStatus(422)->assertJsonValidationErrors('type');
        $this->postJson($this->url('routes'), ['type' => 'inventory.low-stock', 'audience_type' => 'everyone', 'channels' => ['mail']])->assertStatus(422)->assertJsonValidationErrors('audience_type');
        $this->postJson($this->url('routes'), ['type' => 'inventory.low-stock', 'audience_type' => 'eligible', 'channels' => []])->assertStatus(422)->assertJsonValidationErrors('channels');
        $this->postJson($this->url('routes'), ['type' => 'inventory.low-stock', 'audience_type' => 'eligible', 'channels' => ['sms']])->assertStatus(422)->assertJsonValidationErrors('channels.0');
        // billing.invoice is delivered by mail only.
        $this->postJson($this->url('routes'), ['type' => 'billing.invoice', 'audience_type' => 'eligible', 'channels' => ['database']])->assertStatus(422)->assertJsonValidationErrors('channels.0');
        $this->postJson($this->url('routes'), ['type' => 'inventory.low-stock', 'audience_type' => 'member', 'channels' => ['mail']])->assertStatus(422)->assertJsonValidationErrors('audience_id');

        $this->assertSame(0, NotificationRoute::query()->count());
    }

    public function test_a_rule_cannot_be_made_mandatory_through_the_api(): void
    {
        $this->actingAs($this->admin)->postJson($this->url('routes'), [
            'type' => 'inventory.low-stock', 'audience_type' => 'eligible', 'channels' => ['mail'], 'mandatory' => ['mail'],
        ])->assertCreated();

        $this->assertSame([], KinetixNotificationPreferences::registry()->mandatoryOf('inventory.low-stock'));
        $this->assertArrayNotHasKey('mandatory', NotificationRoute::query()->sole()->getAttributes());
    }

    public function test_another_teams_member_role_or_group_is_a_404(): void
    {
        $outsider = $this->member($this->beta, 'Outsider');
        $theirs   = $this->group($this->beta, 'Theirs');

        $this->actingAs($this->admin);
        $base = ['type' => 'inventory.low-stock', 'channels' => ['mail']];

        $this->postJson($this->url('routes'), [...$base, 'audience_type' => 'member', 'audience_id' => $this->ref($outsider)])->assertNotFound();
        $this->postJson($this->url('routes'), [...$base, 'audience_type' => 'group', 'audience_id' => (string) $theirs->getKey()])->assertNotFound();
        $this->postJson($this->url('routes'), [...$base, 'audience_type' => 'role', 'audience_id' => '999'])->assertNotFound();

        $this->assertSame(0, NotificationRoute::query()->count());
    }

    public function test_another_teams_rule_cannot_be_read_changed_or_deleted(): void
    {
        $theirs = $this->route($this->beta, 'inventory.low-stock', AudienceType::Eligible, null, ['mail']);

        $this->actingAs($this->admin);

        $this->putJson($this->url("routes/{$theirs->getKey()}"), ['channels' => ['database']])->assertNotFound();
        $this->deleteJson($this->url("routes/{$theirs->getKey()}"))->assertNotFound();

        $this->assertSame(['mail'], $theirs->fresh()->channels);
        $this->assertNotContains($theirs->getKey(), array_column(array_merge(...array_column($this->getJson($this->url())->json('types'), 'routes')), 'id'));
    }

    public function test_a_rule_is_deleted(): void
    {
        $route = $this->route($this->alpha, 'inventory.low-stock', AudienceType::Eligible, null, ['mail']);

        $this->actingAs($this->admin)->deleteJson($this->url("routes/{$route->getKey()}"))->assertOk();

        $this->assertSame(0, NotificationRoute::query()->count());
    }

    public function test_groups_are_created_edited_and_deleted_with_their_members(): void
    {
        $ada = $this->member($this->alpha, 'Ada');
        $bob = $this->member($this->alpha, 'Bob');

        $this->actingAs($this->admin);

        $id = $this->postJson($this->url('groups'), ['name' => 'Leads', 'description' => 'On call', 'member_ids' => [$this->ref($ada)]])->assertCreated()
            ->assertJsonPath('group.name', 'Leads')
            ->assertJsonPath('group.members.0.name', 'Ada')
            ->json('group.id');

        $this->assertEquals($this->alpha->getKey(), NotificationGroup::query()->sole()->team_id);

        $this->putJson($this->url("groups/{$id}"), ['name' => 'Leads', 'member_ids' => [$this->ref($bob)]])->assertOk()
            ->assertJsonPath('group.members', [['id' => $this->ref($bob), 'name' => 'Bob']]);

        $this->postJson($this->url("groups/{$id}/members"), ['member_ids' => [$this->ref($ada)]])->assertOk();
        $this->assertSame(2, NotificationGroupMember::query()->count());

        $this->deleteJson($this->url("groups/{$id}/members/".rawurlencode($this->ref($bob))))->assertOk()
            ->assertJsonPath('group.members', [['id' => $this->ref($ada), 'name' => 'Ada']]);

        $this->getJson($this->url('groups'))->assertOk()->assertJsonPath('groups.0.name', 'Leads');

        $this->deleteJson($this->url("groups/{$id}"))->assertOk();

        $this->assertSame(0, NotificationGroup::query()->count());
        $this->assertSame(0, NotificationGroupMember::query()->count());
    }

    public function test_deleting_a_group_takes_the_rules_that_pointed_at_it(): void
    {
        $group = $this->group($this->alpha, 'Leads');
        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Group, $group->getKey(), ['mail']);
        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Eligible, null, ['mail']);

        $this->actingAs($this->admin)->deleteJson($this->url("groups/{$group->getKey()}"))->assertOk();

        $this->assertSame(['eligible'], NotificationRoute::query()->get()->map(fn (NotificationRoute $route): string => $route->audience_type->value)->all());
    }

    public function test_a_group_name_is_unique_per_team_only(): void
    {
        $this->group($this->beta, 'Leads');
        $this->group($this->alpha, 'Support');

        $this->actingAs($this->admin);

        $this->postJson($this->url('groups'), ['name' => 'Leads'])->assertCreated();
        $this->postJson($this->url('groups'), ['name' => 'Leads'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson($this->url('groups'), [])->assertStatus(422)->assertJsonValidationErrors('name');

        // A group keeps its own name on save.
        $support = NotificationGroup::query()->where('name', 'Support')->sole();
        $this->putJson($this->url("groups/{$support->getKey()}"), ['name' => 'Support'])->assertOk();
    }

    public function test_only_members_of_the_current_team_can_be_put_in_a_group(): void
    {
        $outsider = $this->member($this->beta, 'Outsider');
        $group    = $this->group($this->alpha, 'Leads');

        $this->actingAs($this->admin);

        $this->postJson($this->url('groups'), ['name' => 'New', 'member_ids' => [$this->ref($outsider)]])->assertNotFound();
        $this->putJson($this->url("groups/{$group->getKey()}"), ['member_ids' => [$this->ref($outsider)]])->assertNotFound();
        $this->postJson($this->url("groups/{$group->getKey()}/members"), ['member_ids' => [$this->ref($outsider)]])->assertNotFound();

        $this->assertSame(0, NotificationGroupMember::query()->count());
        $this->assertSame(1, NotificationGroup::query()->count());
    }

    public function test_another_teams_group_cannot_be_read_changed_or_deleted(): void
    {
        $theirs = $this->group($this->beta, 'Theirs', [$this->member($this->beta, 'Bob')]);
        $ada    = $this->member($this->alpha, 'Ada');

        $this->actingAs($this->admin);

        $this->putJson($this->url("groups/{$theirs->getKey()}"), ['name' => 'Mine'])->assertNotFound();
        $this->deleteJson($this->url("groups/{$theirs->getKey()}"))->assertNotFound();
        $this->postJson($this->url("groups/{$theirs->getKey()}/members"), ['member_ids' => [$this->ref($ada)]])->assertNotFound();
        $this->deleteJson($this->url("groups/{$theirs->getKey()}/members/".rawurlencode($theirs->members()->first()->user_type.'|'.$theirs->members()->first()->user_id)))->assertNotFound();

        $this->assertSame([], $this->getJson($this->url('groups'))->json('groups'));
        $this->assertSame('Theirs', $theirs->fresh()->name);
        $this->assertSame(1, $theirs->members()->count());
    }

    public function test_the_recipients_preview_says_who_gets_it_and_who_is_left_out(): void
    {
        $ada = $this->member($this->alpha, 'Ada');
        $bob = $this->member($this->alpha, 'Bob');
        $this->route($this->alpha, 'billing.invoice', AudienceType::Member, $ada->getKey(), ['mail']);
        $this->route($this->alpha, 'billing.invoice', AudienceType::Member, $bob->getKey(), ['mail']);

        // Only Ada may see invoices.
        Permission::findOrCreate('billing.manage', 'web');
        $ada->givePermissionTo('billing.manage');

        $response = $this->actingAs($this->admin)->getJson($this->url('recipients?type=billing.invoice'))->assertOk();

        $this->assertTrue($response->json('hasRules'));
        $this->assertFalse($response->json('truncated'));
        $this->assertSame([['Ada', ['mail'], null]], array_map(static fn (array $r): array => [$r['name'], $r['channels'], $r['reason']], $response->json('recipients')));
        $this->assertSame([['Bob', [], 'ineligible']], array_map(static fn (array $r): array => [$r['name'], $r['channels'], $r['reason']], $response->json('skipped')));

        $this->getJson($this->url('recipients?type=nope'))->assertStatus(422);
    }

    public function test_the_members_can_come_from_a_callable_array_in_config(): void
    {
        config(['kinetix.notification_routing.members' => [RoutingMembersResolver::class, 'of']]);

        RoutingUser::query()->create(['name' => 'Picked One']);
        RoutingUser::query()->create(['name' => 'Not picked']);

        $names = array_column($this->actingAs($this->admin)->getJson($this->url('audiences'))->json('members'), 'name');

        $this->assertSame(['Picked One'], $names);
    }

    public function test_a_purged_account_leaves_every_group_and_rule(): void
    {
        $ada = $this->member($this->alpha, 'Ada');
        $bob = $this->member($this->alpha, 'Bob');

        $this->group($this->alpha, 'Leads', [$ada, $bob]);
        $this->group($this->beta, 'Theirs', [$ada]);
        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Member, $ada->getKey(), ['mail']);
        $this->route($this->alpha, 'inventory.low-stock', AudienceType::Member, $bob->getKey(), ['mail']);

        GdprPurged::dispatch($ada);

        $this->assertSame([$bob->getKey()], NotificationGroupMember::query()->pluck('user_id')->all());
        $this->assertSame([$this->ref($bob)], NotificationRoute::query()->pluck('audience_id')->all());
    }

    public function test_purging_an_account_dispatches_the_purged_event(): void
    {
        Event::fake([GdprPurged::class]);
        $ada = $this->member($this->alpha, 'Ada');

        app(GdprManager::class)->purge($ada);

        Event::assertDispatched(GdprPurged::class, fn (GdprPurged $event): bool => $event->user->is($ada));
    }

    public function test_inbox_members_are_the_same_through_the_shared_resolver(): void
    {
        $this->member($this->alpha, 'Ada');
        $this->member($this->beta, 'Bob');

        $inbox = app(InboxManager::class);

        // The team model's relation (the default).
        $names = $inbox->members($this->alpha->getKey())->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Admin of alpha', 'Ada'], $names);
        $this->assertEquals($names, KinetixTeams::members($this->alpha->getKey())->pluck('name')->all());

        // An unknown team has no members; no team lists the user model's rows.
        $this->assertSame([], $inbox->members(999)->all());
        $this->assertCount(3, $inbox->members(null));

        // A host callable, and only users back from it count.
        config(['kinetix.inbox.members' => [RoutingMembersResolver::class, 'of']]);
        RoutingUser::query()->create(['name' => 'Picked']);
        $this->assertSame(['Picked'], $inbox->members($this->alpha->getKey())->pluck('name')->all());
    }

    public function test_the_default_config_survives_config_cache(): void
    {
        $block = config('kinetix.notification_routing');

        $this->assertSame($block, eval('return '.var_export($block, true).';'));
        $this->assertNull($block['members']);
    }

    public function test_the_migrations_publish_under_their_own_tag(): void
    {
        $paths = ServiceProvider::pathsToPublish(KinetixServiceProvider::class, 'kinetix-notification-routing-migrations');

        $this->assertCount(2, $paths);
        $this->assertSame(
            ['2026_01_01_000054_create_kinetix_notification_groups_tables.php', '2026_01_01_000055_create_kinetix_notification_routes_table.php'],
            array_map('basename', array_values($paths)),
        );
    }

    public function test_the_doctor_checks_the_module(): void
    {
        Artisan::call('kinetix:doctor', ['--json' => true]);
        $findings = collect(json_decode(Artisan::output(), true)['findings'])->where('section', 'Notification routing');

        $this->assertNotEmpty($findings);
        $this->assertSame(0, $findings->where('level', 'error')->count());
        // `billing.manage` is not a defined ability here.
        $this->assertTrue($findings->contains(fn (array $finding): bool => str_contains(implode(' ', $finding['items']), 'billing.invoice')));
    }

    public function test_the_doctor_flags_a_closure_in_the_members_option(): void
    {
        config(['kinetix.notification_routing.members' => fn (): array => []]);

        Artisan::call('kinetix:doctor', ['--json' => true]);
        $findings = collect(json_decode(Artisan::output(), true)['findings'])->where('section', 'Config cache');

        $this->assertTrue($findings->contains(fn (array $finding): bool => in_array('kinetix.notification_routing.members', $finding['items'], true)));
    }
}
