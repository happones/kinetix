<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Permissions\KinetixRolesSeeder;
use Happones\Kinetix\Tests\Concerns\CreatesPermissionTables;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;

class PermUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    /** spatie infers the permission guard from this property. */
    protected $guard_name = 'web';
}

/**
 * Stand-in for the host's team-ownership rule, as a config-cacheable callable
 * array (a closure in config breaks `config:cache`).
 */
class OwnerBypassStub
{
    public static function check(mixed $user, mixed $team = null): bool
    {
        return $user?->name === 'Owner';
    }
}

class PermissionManagementTest extends TestCase
{
    use CreatesPermissionTables;

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
        // Routes + the `roles` feature register at boot only when enabled.
        $app['config']->set('kinetix.permissions.enabled', true);
        // spatie's Role::users() resolves the model from the guard's provider.
        $app['config']->set('auth.providers.users.model', PermUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPermissionTables();

        KinetixPermissions::feature('posts')->crud();
        // Materialize declared permissions (incl. the built-in roles.manage).
        foreach (KinetixPermissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    private function actingAsManager(): PermUser
    {
        $user = PermUser::create(['name' => 'Manager']);
        // A full role-admin holds every declared permission (so the grant guard
        // lets them assign any of them) plus roles.manage. Limited managers are
        // exercised separately.
        $user->givePermissionTo(KinetixPermissions::all());

        return $user;
    }

    public function test_features_endpoint_returns_the_catalog(): void
    {
        $this->actingAs($this->actingAsManager())
            ->getJson('/_kinetix/permissions/features')
            ->assertOk()
            ->assertJsonFragment(['name' => 'posts'])
            ->assertJsonFragment(['permission' => 'posts.update'])
            ->assertJsonFragment(['name' => 'roles']);
    }

    public function test_manager_can_create_update_and_delete_a_role(): void
    {
        $manager = $this->actingAsManager();

        $this->actingAs($manager)
            ->postJson('/_kinetix/permissions/roles', [
                'name'        => 'editor',
                'permissions' => ['posts.view', 'posts.update'],
            ])
            ->assertCreated()
            ->assertJsonFragment(['name' => 'editor']);

        $role = Role::where('name', 'editor')->firstOrFail();
        $this->assertEqualsCanonicalizing(['posts.view', 'posts.update'], $role->permissions->pluck('name')->all());

        $this->actingAs($manager)
            ->putJson("/_kinetix/permissions/roles/{$role->id}", [
                'permissions' => ['posts.view'],
            ])
            ->assertOk();
        $this->assertSame(['posts.view'], $role->fresh()->permissions->pluck('name')->all());

        $this->actingAs($manager)
            ->deleteJson("/_kinetix/permissions/roles/{$role->id}")
            ->assertOk();
        $this->assertDatabaseMissing('roles', ['name' => 'editor']);
    }

    public function test_roles_index_includes_the_member_count(): void
    {
        $manager = $this->actingAsManager();

        Role::findOrCreate('editor', 'web');
        $manager->assignRole('editor');

        $response = $this->actingAs($manager)->getJson('/_kinetix/permissions/roles')->assertOk();

        $editor = collect($response->json())->firstWhere('name', 'editor');
        $this->assertSame(1, $editor['usersCount']);
    }

    public function test_users_without_manage_permission_are_denied(): void
    {
        $user = PermUser::create(['name' => 'Nobody']);

        $this->actingAs($user)
            ->getJson('/_kinetix/permissions/roles')
            ->assertForbidden();
    }

    public function test_unregistered_permissions_are_rejected(): void
    {
        $this->actingAs($this->actingAsManager())
            ->postJson('/_kinetix/permissions/roles', [
                'name'        => 'bogus',
                'permissions' => ['made.up.permission'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('permissions.0');

        $this->assertDatabaseMissing('roles', ['name' => 'bogus']);
    }

    public function test_a_manager_cannot_grant_permissions_they_do_not_hold(): void
    {
        // A registered permission the limited manager does NOT hold.
        KinetixPermissions::feature('billing')->ability('manage');
        Permission::findOrCreate('billing.manage', 'web');

        $manager = PermUser::create(['name' => 'Limited']);
        $manager->givePermissionTo(['roles.manage', 'posts.view']);

        // Granting a held permission works…
        $this->actingAs($manager)
            ->postJson('/_kinetix/permissions/roles', [
                'name'        => 'readers',
                'permissions' => ['posts.view'],
            ])
            ->assertCreated();

        // …but escalating to one they don't hold is refused.
        $this->actingAs($manager)
            ->postJson('/_kinetix/permissions/roles', [
                'name'        => 'billers',
                'permissions' => ['billing.manage'],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('roles', ['name' => 'billers']);
    }

    public function test_owner_whose_permissions_come_from_gate_before_can_grant_them(): void
    {
        // Regression: a team owner is granted every ability dynamically by the
        // host app via Gate::before ($user->ownsTeam(...)), with NO Spatie rows.
        // The anti-escalation guard consults the Gate, not stored rows, so the
        // owner is not wrongly 403'd ("cannot grant permissions you do not hold").
        $owner = PermUser::create(['name' => 'Owner']);
        Gate::before(static fn ($user, string $ability): ?bool => (int) $user->getKey() === (int) $owner->getKey() ? true : null);

        // The owner holds zero model_has_permissions / role_has_permissions rows.
        $this->assertTrue($owner->getAllPermissions()->isEmpty());

        $this->actingAs($owner)
            ->postJson('/_kinetix/permissions/roles', [
                'name'        => 'editor',
                'permissions' => ['posts.view', 'posts.update'],
            ])
            ->assertCreated();

        $role = Role::where('name', 'editor')->firstOrFail();
        $this->assertEqualsCanonicalizing(['posts.view', 'posts.update'], $role->permissions->pluck('name')->all());

        // And updating (which also runs the self-lockout guard) works too.
        $this->actingAs($owner)
            ->putJson("/_kinetix/permissions/roles/{$role->id}", ['permissions' => ['posts.view']])
            ->assertOk();
    }

    /**
     * @return array{enabled: bool, permissions: array<int, string>, roles: array<int, string>, isSuperAdmin: bool}
     */
    private function sharedPermissions(): array
    {
        /** @var callable $shared */
        $shared = Inertia::getShared('kinetix_permissions');

        return value($shared);
    }

    public function test_owner_bypass_capabilities_are_shared_to_the_frontend(): void
    {
        // Regression (frontend parity): the `kinetix_permissions` Inertia prop
        // that builds the SPA's can() map must reflect Gate-granted abilities,
        // not just stored rows — otherwise a team owner (whose rights come from
        // `ownsTeam()`, never a stored row) sees an empty map and the UI hides
        // everything the server would authorize.
        //
        // This is the DOCUMENTED dynamic grant, and the default `auto` mode
        // resolves it with ONE verdict rather than a Gate call per ability:
        // the bypass is scoped to registry keys, so it grants all or none.
        config()->set('kinetix.permissions.owner_bypass', [OwnerBypassStub::class, 'check']);

        $owner = PermUser::create(['name' => 'Owner']);
        $this->actingAs($owner);

        $this->assertTrue($owner->getAllPermissions()->isEmpty());

        $data = $this->sharedPermissions();

        $this->assertTrue($data['enabled']);
        $this->assertContains('posts.view', $data['permissions']);
        $this->assertContains('roles.manage', $data['permissions']);
    }

    public function test_an_ability_the_app_defined_on_the_gate_is_shared_in_auto_mode(): void
    {
        // The other dynamic grant `auto` covers: an explicit Gate::define()
        // over a registry key. `Gate::has()` is an array lookup, so this stays
        // cheap no matter how large the catalog grows.
        Gate::define('posts.view', static fn (): bool => true);

        $user = PermUser::create(['name' => 'Jane']);
        $this->actingAs($user);

        $data = $this->sharedPermissions();

        $this->assertContains('posts.view', $data['permissions']);
        // Nothing else leaks in from the catalog.
        $this->assertNotContains('posts.update', $data['permissions']);
    }

    public function test_a_bespoke_gate_before_needs_the_sweep_mode(): void
    {
        // A hand-written `Gate::before` is NOT something Kinetix can discover
        // without asking the Gate about every registered ability — the ~40ms
        // per page load that `auto` exists to avoid. The docs steer apps to
        // `owner_bypass` instead; those that insist opt into the cost.
        $subject = PermUser::create(['name' => 'Bespoke']);
        Gate::before(static fn ($user, string $ability): ?bool => (int) $user->getKey() === (int) $subject->getKey() ? true : null);

        $this->actingAs($subject);

        // Default mode does not see it (fails closed: the UI hides what the
        // server would allow, rather than showing what it would refuse).
        $this->assertNotContains('posts.view', $this->sharedPermissions()['permissions']);

        config()->set('kinetix.permissions.dynamic_grants', 'sweep');

        $data = $this->sharedPermissions();
        $this->assertContains('posts.view', $data['permissions']);
        $this->assertContains('roles.manage', $data['permissions']);
    }

    public function test_the_off_mode_shares_stored_rows_only(): void
    {
        config()->set('kinetix.permissions.dynamic_grants', 'off');
        config()->set('kinetix.permissions.owner_bypass', [OwnerBypassStub::class, 'check']);

        $owner = PermUser::create(['name' => 'Owner']);
        $this->actingAs($owner);

        $this->assertSame([], $this->sharedPermissions()['permissions']);
    }

    public function test_stored_permissions_are_always_shared_whatever_the_mode(): void
    {
        $role = Role::findOrCreate('editor', 'web');
        $role->syncPermissions(['posts.view']);

        $user = PermUser::create(['name' => 'Jane']);
        $user->assignRole('editor');
        $this->actingAs($user);

        foreach (['auto', 'sweep', 'off'] as $mode) {
            config()->set('kinetix.permissions.dynamic_grants', $mode);

            $this->assertContains(
                'posts.view',
                $this->sharedPermissions()['permissions'],
                "stored rows must survive dynamic_grants={$mode}",
            );
        }
    }

    public function test_the_super_admin_role_is_protected(): void
    {
        $manager    = $this->actingAsManager();
        $superAdmin = Role::findOrCreate('super-admin', 'web');

        $this->actingAs($manager)
            ->putJson("/_kinetix/permissions/roles/{$superAdmin->id}", ['name' => 'renamed'])
            ->assertForbidden();

        $this->actingAs($manager)
            ->deleteJson("/_kinetix/permissions/roles/{$superAdmin->id}")
            ->assertForbidden();

        // Nor can it be re-created / hijacked through the store endpoint.
        $this->actingAs($manager)
            ->postJson('/_kinetix/permissions/roles', ['name' => 'super-admin', 'permissions' => []])
            ->assertForbidden();

        $this->assertDatabaseHas('roles', ['name' => 'super-admin']);
    }

    public function test_a_manager_cannot_strip_their_own_role_management_access(): void
    {
        // This manager's `roles.manage` comes solely from an assigned role.
        $roleAdmin = Role::findOrCreate('role-admin', 'web');
        $roleAdmin->syncPermissions(['roles.manage', 'posts.view']);

        $manager = PermUser::create(['name' => 'RoleAdmin']);
        $manager->assignRole('role-admin');

        // Removing roles.manage from their only source is blocked and rolled back.
        $this->actingAs($manager)
            ->putJson("/_kinetix/permissions/roles/{$roleAdmin->id}", [
                'permissions' => ['posts.view'],
            ])
            ->assertForbidden();

        $this->assertContains(
            'roles.manage',
            $roleAdmin->fresh()->permissions->pluck('name')->all(),
        );
    }

    public function test_seeder_creates_the_preset_roles(): void
    {
        (new KinetixRolesSeeder)->run();

        $this->assertTrue(Role::where('name', 'admin')->exists());
        $this->assertTrue(Role::where('name', 'editor')->exists());
        $this->assertTrue(Role::where('name', 'viewer')->exists());
        $this->assertTrue(Role::where('name', 'super-admin')->exists());

        // editor excludes destructive abilities; viewer is read-only.
        $editor = Role::where('name', 'editor')->first()->permissions->pluck('name');
        $this->assertContains('posts.update', $editor->all());
        $this->assertNotContains('posts.delete', $editor->all());

        $viewer = Role::where('name', 'viewer')->first()->permissions->pluck('name');
        $this->assertEqualsCanonicalizing(['posts.viewAny', 'posts.view'], $viewer->values()->all());
    }
}
