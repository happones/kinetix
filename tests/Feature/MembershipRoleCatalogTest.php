<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Membership\MemberProvision;
use Happones\Kinetix\Membership\MemberProvisionStatus;
use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Tests\Concerns\CreatesMembershipTables;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionServiceProvider;

/**
 * Roles the app keeps in its own logic, not the Roles screen: a label and a
 * description for the picker, and assignment handed to the app.
 */
class AppMemberRoles
{
    /** @var array<int, array{0: string|null, 1: string|null}> */
    public static array $synced = [];

    /**
     * @return array<int, array<string, string>>
     */
    public function __invoke(int|string|null $teamId): array
    {
        return [
            ['name' => 'cashier', 'label' => 'Cashier', 'description' => 'Rings up sales at the till.'],
            ['name' => 'supervisor', 'label' => 'Shift supervisor'],
        ];
    }

    public function sync(Model $user, ?string $to, ?string $from, MemberProvision $provision): void
    {
        static::$synced[] = [$from, $to];
    }
}

/**
 * The member role picker and the Roles screen: by default the picker offers
 * exactly the roles the Roles screen has; the owner's super-admin is never
 * assignable whatever the configuration says; and an app can bring roles from
 * its own logic instead.
 */
class MembershipRoleCatalogTest extends TestCase
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
        $app['config']->set('kinetix.permissions.enabled', true);
        $app['config']->set('kinetix.membership.enabled', true);
        $app['config']->set('kinetix.membership.user_model', DynamicRolesUser::class);
        $app['config']->set('kinetix.membership.assignable_roles', null);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMembershipTables();

        foreach (KinetixPermissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Role::findOrCreate('super-admin', 'web');
        Role::findOrCreate('editor', 'web')->syncPermissions(['members.viewAny', 'members.provision']);
        Role::findOrCreate('viewer', 'web')->syncPermissions(['members.viewAny']);

        AppMemberRoles::$synced = [];
    }

    public function test_by_default_the_picker_offers_the_roles_screen_roles_without_super_admin(): void
    {
        // Created a second ago in the Roles screen: offered at once.
        Role::findOrCreate('auditor', 'web');

        $this->actingAs($this->manager())
            ->getJson('/_kinetix/members')
            ->assertOk()
            ->assertJsonPath('assignable_roles', ['auditor', 'editor', 'viewer'])
            ->assertJsonPath('reserved_roles', ['super-admin']);
    }

    public function test_the_picker_says_how_many_permissions_each_role_grants(): void
    {
        $response = $this->actingAs($this->manager())->getJson('/_kinetix/members')->assertOk();

        $roles = collect($response->json('roles'))->keyBy('name');

        $this->assertSame(2, $roles['editor']['permissions']);
        $this->assertSame(1, $roles['viewer']['permissions']);
        $this->assertNull($roles['editor']['label']);
        $response->assertJsonPath('identifier_field', 'email');
    }

    public function test_it_tells_the_ui_which_actions_the_viewer_may_take(): void
    {
        $this->actingAs($this->manager())
            ->getJson('/_kinetix/members')
            ->assertOk()
            ->assertJsonPath('can.provision', true)
            ->assertJsonPath('can.update', false)
            ->assertJsonPath('can.revoke', false)
            ->assertJsonPath('can.credentials', false);
    }

    public function test_super_admin_is_never_assignable_even_when_a_list_names_it(): void
    {
        Notification::fake();
        config()->set('kinetix.membership.assignable_roles', ['editor', 'super-admin']);

        $this->actingAs($this->manager())
            ->getJson('/_kinetix/members')
            ->assertJsonPath('assignable_roles', ['editor']);

        $this->actingAs($this->manager())
            ->postJson('/_kinetix/members', ['email' => 'boss@example.com', 'role' => 'super-admin'])
            ->assertStatus(422);

        $this->assertDatabaseMissing('kinetix_member_provisions', ['email' => 'boss@example.com']);
    }

    public function test_a_member_cannot_be_promoted_to_super_admin(): void
    {
        $provision = MemberProvision::create([
            'email'      => 'member@example.com',
            'role'       => 'editor',
            'status'     => MemberProvisionStatus::Active,
            'expires_at' => null,
        ]);

        $manager = $this->manager();
        $manager->givePermissionTo('members.update');

        $this->actingAs($manager)
            ->putJson("/_kinetix/members/{$provision->id}", ['role' => 'super-admin'])
            ->assertStatus(422);

        $this->assertSame('editor', $provision->fresh()->role);
    }

    public function test_without_the_permissions_module_the_default_is_editor_and_viewer(): void
    {
        config()->set('kinetix.permissions.enabled', false);

        $this->actingAs($this->manager())
            ->getJson('/_kinetix/members')
            ->assertJsonPath('assignable_roles', ['editor', 'viewer']);
    }

    public function test_app_roles_carry_their_own_label_and_description(): void
    {
        config()->set('kinetix.membership.assignable_roles', AppMemberRoles::class);

        $response = $this->actingAs($this->manager())->getJson('/_kinetix/members')->assertOk();

        $response->assertJsonPath('assignable_roles', ['cashier', 'supervisor']);

        $roles = collect($response->json('roles'))->keyBy('name');
        $this->assertSame('Cashier', $roles['cashier']['label']);
        $this->assertSame('Rings up sales at the till.', $roles['cashier']['description']);
        $this->assertSame('Shift supervisor', $roles['supervisor']['label']);
        $this->assertNull($roles['supervisor']['description']);
        // Not Roles-screen rows: nothing to count.
        $this->assertNull($roles['cashier']['permissions']);
    }

    public function test_assign_role_hands_every_role_change_to_the_app(): void
    {
        config()->set('kinetix.membership.assignable_roles', AppMemberRoles::class);
        config()->set('kinetix.membership.assign_role', [AppMemberRoles::class, 'sync']);

        $provision = MemberProvision::create([
            'email'      => 'till@example.com',
            'role'       => 'cashier',
            'status'     => MemberProvisionStatus::Pending,
            'expires_at' => now()->addDay(),
        ]);

        // Activation grants the role through the app — "cashier" is no spatie
        // role, so spatie's assignRole would have thrown here.
        $this->post($this->activationUrl($provision), [
            'name'                  => 'Till',
            'password'              => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertRedirect();

        $manager = $this->manager();
        $manager->givePermissionTo(['members.update', 'members.revoke']);

        $this->actingAs($manager)
            ->putJson("/_kinetix/members/{$provision->id}", ['role' => 'supervisor'])
            ->assertOk();

        $this->actingAs($manager)
            ->deleteJson("/_kinetix/members/{$provision->id}")
            ->assertOk();

        $this->assertSame([
            [null, 'cashier'],
            ['cashier', 'supervisor'],
            ['supervisor', null],
        ], AppMemberRoles::$synced);

        $user = DynamicRolesUser::query()->where('email', 'till@example.com')->firstOrFail();
        $this->assertCount(0, $user->roles);
    }

    private function manager(): DynamicRolesUser
    {
        $user = DynamicRolesUser::create(['name' => 'Manager', 'email' => 'manager@example.com']);
        $user->givePermissionTo('members.viewAny');
        $user->givePermissionTo('members.provision');

        return $user;
    }

    private function activationUrl(MemberProvision $provision): string
    {
        return URL::temporarySignedRoute(
            'kinetix.membership.activate.show',
            now()->addDay(),
            ['provision' => $provision->id],
        );
    }
}
