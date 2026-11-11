<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Membership\MembershipController;
use Happones\Kinetix\NotificationPreferences\NotificationTypeRegistry;
use Happones\Kinetix\NotificationRouting\AudienceType;
use Happones\Kinetix\NotificationRouting\KinetixNotificationRouting;
use Happones\Kinetix\NotificationRouting\NotificationRoute;
use Happones\Kinetix\NotificationRouting\PendingRouting;
use Happones\Kinetix\Permissions\RoleLabels;
use Happones\Kinetix\Tests\Concerns\BuildsRoutingFixture;
use Happones\Kinetix\Tests\Fakes\RoutedAlert;
use Happones\Kinetix\Tests\Fakes\RoutingCallbacks;
use Happones\Kinetix\Tests\Fakes\RoutingInstanceCallbacks;
use Happones\Kinetix\Tests\Fakes\RoutingTeam;
use Happones\Kinetix\Tests\Fakes\RoutingUser;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use InvalidArgumentException;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionServiceProvider;

/**
 * Routing audiences without super-admin, role labels, the fallback of a type,
 * and types that are not routable or not offered to a team.
 */
class NotificationRoutingRequestsTest extends TestCase
{
    use BuildsRoutingFixture;

    protected RoutingTeam $alpha;

    protected RoutingTeam $beta;

    protected RoutingUser $admin;

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
        $app['config']->set('kinetix.teams', $this->teamsOn());
        $app['config']->set('kinetix.notification_routing.enabled', true);
        $app['config']->set('kinetix.notification_preferences.enabled', true);
        $app['config']->set('kinetix.notification_preferences.channels', ['mail' => 'Email', 'database' => 'In-app']);
        $app['config']->set('kinetix.notification_preferences.types', ['inventory.low-stock' => 'Low stock', 'personal.document' => 'Your document']);
        $app['config']->set('auth.providers.users.model', RoutingUser::class);
    }

    protected function teamsOn(): bool
    {
        return true;
    }

    protected function setUp(): void
    {
        parent::setUp();

        RoutingUser::$activeTeam = null;
        RoutingCallbacks::reset();
        $this->createRoutingTables();
        $this->alpha = $this->team('alpha');
        $this->beta  = $this->team('beta');
        $this->admin = $this->admin($this->alpha);
    }

    protected function base(): string
    {
        return $this->teamsOn() ? '/alpha/_kinetix/notification-routing' : '/_kinetix/notification-routing';
    }

    /**
     * The team the rules are stored under.
     */
    protected function ruleScope(): int|string|null
    {
        return $this->teamKey();
    }

    protected function teamKey(): int|string|null
    {
        return $this->teamsOn() ? $this->alpha->getKey() : null;
    }

    /**
     * A member rule, stored under the team the module scopes to (none without teams).
     */
    private function ruleFor(string $type, RoutingUser $user): NotificationRoute
    {
        return NotificationRoute::query()->create([
            'team_id'       => $this->ruleScope(),
            'type'          => $type,
            'audience_type' => AudienceType::Member,
            'audience_id'   => $this->ref($user),
            'channels'      => ['mail'],
        ]);
    }

    protected function routing(): PendingRouting
    {
        return KinetixNotificationRouting::for('inventory.low-stock', $this->teamKey());
    }

    /**
     * @return list<string>
     */
    protected function types(): array
    {
        return array_column($this->actingAs($this->admin)->getJson($this->base())->assertOk()->json('types'), 'key');
    }

    public function test_the_super_admin_role_is_not_offered_nor_accepted(): void
    {
        $super = $this->role('super-admin');
        $this->role('admin');

        $roles = $this->actingAs($this->admin)->getJson($this->base().'/audiences')->assertOk()->json('roles');

        $this->assertNotContains('super-admin', array_column($roles, 'name'));
        $this->assertContains('Administrator', array_column($roles, 'name'));

        $this->postJson($this->base().'/routes', ['type' => 'inventory.low-stock', 'audience_type' => 'role', 'audience_id' => (string) $super->getKey(), 'channels' => ['mail']])
            ->assertNotFound();
    }

    public function test_other_protected_roles_stay_routable(): void
    {
        config(['kinetix.permissions.protected_roles' => ['super-admin', 'owner']]);
        $owner = $this->role('owner');

        $this->actingAs($this->admin)->postJson($this->base().'/routes', ['type' => 'inventory.low-stock', 'audience_type' => 'role', 'audience_id' => (string) $owner->getKey(), 'channels' => ['mail']])
            ->assertCreated();
    }

    public function test_role_labels_come_from_the_host_then_the_presets_then_the_name(): void
    {
        $this->role('admin');
        $this->role('editor');
        $this->role('sales');

        $this->assertSame('Administrator', RoleLabels::label('admin', 1));
        $this->assertSame('Editor', RoleLabels::label('editor'));
        $this->assertNull(RoleLabels::label('sales'));

        config(['kinetix.permissions.role_label' => [RoutingCallbacks::class, 'label']]);

        $this->assertSame('Company admin', RoleLabels::label('admin', 1));
        $this->assertSame('Editor', RoleLabels::label('editor'));

        $roles = $this->actingAs($this->admin)->getJson($this->base().'/audiences')->json('roles');

        $this->assertSame(['Company admin', 'Editor', 'sales'], array_column($roles, 'name'));
    }

    public function test_labels_are_translated_per_request(): void
    {
        app()->setLocale('es');

        $this->assertSame('Administrador', RoleLabels::label('admin'));

        app()->setLocale('en');

        $this->assertSame('Administrator', RoleLabels::label('admin'));
    }

    public function test_the_member_roles_payload_carries_the_preset_label(): void
    {
        $details = (new ReflectionMethod(MembershipController::class, 'roleDetails'))
            ->invoke(app(MembershipController::class), ['admin', 'sales'], $this->teamKey());

        $this->assertSame('Administrator', $details[0]['label']);
        $this->assertNull($details[1]['label']);
    }

    public function test_preview_without_rules_says_it_fell_back_and_lists_the_default_recipients(): void
    {
        $owner = $this->member($this->alpha, 'Owner');
        $this->actingAs($this->admin);

        $none = $this->getJson($this->base().'/recipients?type=inventory.low-stock')->assertOk()->json();
        $this->assertFalse($none['hasRules']);
        $this->assertFalse($none['fellBack']);
        $this->assertSame([], $none['recipients']);

        KinetixNotificationRouting::type('inventory.low-stock')->fallbackUsing([RoutingCallbacks::class, 'fallback']);
        RoutingCallbacks::$fallbackUsers = [$owner];

        $json = $this->getJson($this->base().'/recipients?type=inventory.low-stock')->assertOk()->json();

        $this->assertTrue($json['fellBack']);
        $this->assertFalse($json['hasRules']);
        $this->assertSame(['Owner'], array_column($json['recipients'], 'name'));
        $this->assertSame($this->teamKey(), RoutingCallbacks::$fallbackCalls[0][0]);
        $this->assertNull(RoutingCallbacks::$fallbackCalls[0][1]);
    }

    public function test_fallback_using_serves_a_send_and_the_senders_fallback_wins(): void
    {
        NotificationFacade::fake();

        $default  = $this->member($this->alpha, 'Default');
        $explicit = $this->member($this->alpha, 'Explicit');

        KinetixNotificationRouting::type('inventory.low-stock')->fallbackUsing([RoutingCallbacks::class, 'fallback']);
        RoutingCallbacks::$fallbackUsers = [$default];

        $this->routing()->notifyNow(new RoutedAlert);

        NotificationFacade::assertSentTo($default, RoutedAlert::class);
        $this->assertInstanceOf(RoutedAlert::class, RoutingCallbacks::$fallbackCalls[0][1]);

        $this->routing()->fallback([$explicit])->notifyNow(new RoutedAlert);

        NotificationFacade::assertSentTo($explicit, RoutedAlert::class);
        $this->assertCount(1, RoutingCallbacks::$fallbackCalls);
    }

    public function test_fallback_using_is_not_used_when_a_rule_exists(): void
    {
        $default = $this->member($this->alpha, 'Default');
        $this->ruleFor('inventory.low-stock', $this->member($this->alpha, 'Ada'));

        KinetixNotificationRouting::type('inventory.low-stock')->fallbackUsing([RoutingCallbacks::class, 'fallback']);
        RoutingCallbacks::$fallbackUsers = [$default];

        $this->assertFalse($this->routing()->recipients()->fellBack);
        $this->assertSame([], RoutingCallbacks::$fallbackCalls);
    }

    public function test_fallback_users_outside_the_team_are_dropped(): void
    {
        $mine    = $this->member($this->alpha, 'Mine');
        $foreign = $this->member($this->beta, 'Foreign');

        KinetixNotificationRouting::type('inventory.low-stock')->fallbackUsing([RoutingCallbacks::class, 'fallback']);
        RoutingCallbacks::$fallbackUsers = [$mine, $foreign];

        $plan  = $this->routing()->recipients();
        $names = array_map(static fn ($recipient): string => (string) $recipient->user->getAttribute('name'), $plan->recipients);

        if ($this->teamsOn()) {
            $this->assertSame(['Mine'], $names);
        } else {
            $this->assertEqualsCanonicalizing(['Mine', 'Foreign'], $names);
        }
    }

    public function test_the_fallback_description_is_localized_per_request(): void
    {
        KinetixNotificationRouting::type('inventory.low-stock')
            ->fallbackDescription('kinetix::kinetix.role_preset_admin')
            ->fallbackUsing([RoutingCallbacks::class, 'fallback']);
        KinetixNotificationRouting::type('personal.document')->fallbackDescription('Plain text');

        $this->actingAs($this->admin);

        $types = collect($this->getJson($this->base())->json('types'))->keyBy('key');
        $this->assertSame('Administrator', $types['inventory.low-stock']['fallback']);
        $this->assertTrue($types['inventory.low-stock']['hasFallback']);
        $this->assertSame('Plain text', $types['personal.document']['fallback']);
        $this->assertFalse($types['personal.document']['hasFallback']);

        app()->setLocale('es');

        $this->assertSame('Administrador', collect($this->getJson($this->base())->json('types'))->keyBy('key')['inventory.low-stock']['fallback']);
    }

    public function test_a_type_without_fallback_reports_none(): void
    {
        $type = collect($this->actingAs($this->admin)->getJson($this->base())->json('types'))->firstWhere('key', 'inventory.low-stock');

        $this->assertNull($type['fallback']);
        $this->assertFalse($type['hasFallback']);
    }

    public function test_a_type_that_is_not_routable_is_hidden_refused_and_ignored_when_sending(): void
    {
        NotificationFacade::fake();

        $ada      = $this->member($this->alpha, 'Ada');
        $fallback = $this->member($this->alpha, 'Fallback');
        $this->ruleFor('personal.document', $ada);

        KinetixNotificationRouting::type('personal.document')->routable(false)->fallbackUsing([RoutingCallbacks::class, 'fallback']);
        RoutingCallbacks::$fallbackUsers = [$fallback];

        $this->assertNotContains('personal.document', $this->types());
        $this->assertContains('inventory.low-stock', $this->types());

        $this->postJson($this->base().'/routes', ['type' => 'personal.document', 'audience_type' => 'eligible', 'channels' => ['mail']])->assertUnprocessable();
        $this->getJson($this->base().'/recipients?type=personal.document')->assertUnprocessable();

        KinetixNotificationRouting::for('personal.document', $this->teamKey())->notifyNow(new RoutedAlert);

        NotificationFacade::assertNotSentTo($ada, RoutedAlert::class);
        NotificationFacade::assertSentTo($fallback, RoutedAlert::class);
    }

    public function test_kinetix_types_are_not_routable_unless_declared(): void
    {
        $registry = app(NotificationTypeRegistry::class);
        $registry->register(['kinetix.custom' => 'Custom']);

        $this->assertNotContains('kinetix.custom', $this->types());

        KinetixNotificationRouting::type('kinetix.custom')->routable();

        $this->assertContains('kinetix.custom', $this->types());
    }

    public function test_availability_decides_the_screen_the_store_the_update_and_the_send(): void
    {
        NotificationFacade::fake();

        $ada      = $this->member($this->alpha, 'Ada');
        $fallback = $this->member($this->alpha, 'Fallback');
        $route    = $this->ruleFor('inventory.low-stock', $ada);

        KinetixNotificationRouting::type('inventory.low-stock')
            ->availableWhen([RoutingCallbacks::class, 'available'])
            ->fallbackUsing([RoutingCallbacks::class, 'fallback']);
        RoutingCallbacks::$fallbackUsers = [$fallback];

        $this->actingAs($this->admin);

        $this->assertContains('inventory.low-stock', $this->types());
        $this->assertContains([$this->teamKey(), 'inventory.low-stock'], RoutingCallbacks::$availabilityCalls);
        $this->putJson($this->base().'/routes/'.$route->getKey(), ['channels' => ['database']])->assertOk();

        RoutingCallbacks::$available = false;

        $this->assertNotContains('inventory.low-stock', $this->types());
        $this->postJson($this->base().'/routes', ['type' => 'inventory.low-stock', 'audience_type' => 'eligible', 'channels' => ['mail']])->assertUnprocessable();
        $this->getJson($this->base().'/recipients?type=inventory.low-stock')->assertUnprocessable();
        $this->putJson($this->base().'/routes/'.$route->getKey(), ['channels' => ['mail']])->assertNotFound();

        KinetixNotificationRouting::for('inventory.low-stock', $this->teamKey())->notifyNow(new RoutedAlert);

        NotificationFacade::assertNotSentTo($ada, RoutedAlert::class);
        NotificationFacade::assertSentTo($fallback, RoutedAlert::class);

        $this->deleteJson($this->base().'/routes/'.$route->getKey())->assertOk();
    }

    public function test_two_teams_can_differ_in_availability_on_the_screen_and_when_sending(): void
    {
        if (! $this->teamsOn() || $this->ruleScope() === null) {
            $this->markTestSkipped('Needs team-scoped rules.');
        }

        NotificationFacade::fake();

        $adaAlpha = $this->member($this->alpha, 'Ada');
        $adaBeta  = $this->member($this->beta, 'Bea');
        $fbAlpha  = $this->member($this->alpha, 'FbAlpha');
        $fbBeta   = $this->member($this->beta, 'FbBeta');

        KinetixNotificationRouting::type('inventory.low-stock')
            ->availableWhen([RoutingCallbacks::class, 'available'])
            ->fallbackUsing([RoutingCallbacks::class, 'fallback']);
        RoutingCallbacks::$availableByTeam = [$this->alpha->getKey() => true, $this->beta->getKey() => false];
        RoutingCallbacks::$fallbackUsers   = [$fbAlpha, $fbBeta];

        $this->assertContains('inventory.low-stock', $this->types());

        $betaAdmin = $this->admin($this->beta);
        $betaTypes = array_column($this->actingAs($betaAdmin)->getJson('/beta/_kinetix/notification-routing')->json('types'), 'key');
        $this->assertNotContains('inventory.low-stock', $betaTypes);

        NotificationRoute::query()->create(['team_id' => $this->beta->getKey(), 'type' => 'inventory.low-stock', 'audience_type' => AudienceType::Member, 'audience_id' => $this->ref($adaBeta), 'channels' => ['mail']]);
        NotificationRoute::query()->create(['team_id' => $this->alpha->getKey(), 'type' => 'inventory.low-stock', 'audience_type' => AudienceType::Member, 'audience_id' => $this->ref($adaAlpha), 'channels' => ['mail']]);

        KinetixNotificationRouting::for('inventory.low-stock', $this->alpha->getKey())->notifyNow(new RoutedAlert);
        KinetixNotificationRouting::for('inventory.low-stock', $this->beta->getKey())->notifyNow(new RoutedAlert);

        NotificationFacade::assertSentTo($adaAlpha, RoutedAlert::class);
        NotificationFacade::assertNotSentTo($adaBeta, RoutedAlert::class);
        NotificationFacade::assertSentTo($fbBeta, RoutedAlert::class);
        NotificationFacade::assertNotSentTo($fbAlpha, RoutedAlert::class);
    }

    public function test_availability_is_asked_once_per_type_per_request(): void
    {
        KinetixNotificationRouting::type('inventory.low-stock')->availableWhen([RoutingCallbacks::class, 'available']);

        $this->actingAs($this->admin)->getJson($this->base())->assertOk();

        $asked = array_filter(RoutingCallbacks::$availabilityCalls, static fn (array $call): bool => $call[1] === 'inventory.low-stock');

        $this->assertCount(1, $asked);
    }

    public function test_the_membership_payload_reflects_the_host_role_label(): void
    {
        config(['kinetix.permissions.role_label' => [RoutingCallbacks::class, 'label']]);

        $details = (new ReflectionMethod(MembershipController::class, 'roleDetails'))
            ->invoke(app(MembershipController::class), ['admin', 'editor'], $this->teamKey());

        $this->assertSame('Company admin', $details[0]['label']);
        $this->assertSame('Editor', $details[1]['label']);
    }

    public function test_the_host_role_label_is_asked_once_per_role_per_request(): void
    {
        $this->role('admin');
        config(['kinetix.permissions.role_label' => [RoutingCallbacks::class, 'countingLabel']]);

        $this->actingAs($this->admin)->getJson($this->base().'/audiences')->assertOk();

        $this->assertSame(1, RoutingCallbacks::$labelCalls);
    }

    public function test_callbacks_are_resolved_on_every_call_not_held_as_instances(): void
    {
        RoutingInstanceCallbacks::$instances = 0;

        KinetixNotificationRouting::type('inventory.low-stock')->availableWhen([RoutingInstanceCallbacks::class, 'available']);
        $afterRegistration = RoutingInstanceCallbacks::$instances;

        $this->actingAs($this->admin)->getJson($this->base())->assertOk();
        $this->actingAs($this->admin)->getJson($this->base())->assertOk();

        $this->assertGreaterThan($afterRegistration, RoutingInstanceCallbacks::$instances);
    }

    public function test_a_null_fallback_result_is_an_empty_fallback(): void
    {
        KinetixNotificationRouting::type('inventory.low-stock')->fallbackUsing([RoutingCallbacks::class, 'nothing']);

        $this->assertFalse($this->routing()->recipients()->fellBack);
    }

    public function test_a_callback_that_cannot_be_resolved_fails_at_registration(): void
    {
        $this->expectException(InvalidArgumentException::class);

        KinetixNotificationRouting::type('inventory.low-stock')->availableWhen('not a callback');
    }

    public function test_an_unresolvable_fallback_callback_fails_at_registration(): void
    {
        $this->expectException(InvalidArgumentException::class);

        KinetixNotificationRouting::type('inventory.low-stock')->fallbackUsing(['Nope\\Missing', 'nothing']);
    }
}
