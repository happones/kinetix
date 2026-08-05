<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Permissions\AssignableRoles;
use Happones\Kinetix\Permissions\PermissionRegistry;
use Happones\Kinetix\Tests\Concerns\CreatesPermissionTables;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;

class GuardedPermUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $guard_name = 'web';
}

/**
 * The v0.140.0 role guardrails: team-scoped usersCount, the delete-in-use
 * block, super-admin-only global role creation, the permission-DELTA tamper
 * guard, and the AssignableRoles helper unioning team + global roles.
 */
class PermissionRoleGuardsTest extends TestCase
{
    use CreatesPermissionTables;

    private const TEAM_A = 1;

    private const TEAM_B = 2;

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
        $app['config']->set('kinetix.permissions.teams', true);
        $app['config']->set('permission.teams', true);

        // Role::users() resolves its target model from the auth provider —
        // point it at this test's user so pivot counts see the assignments.
        $app['config']->set('auth.providers.users.model', GuardedPermUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPermissionTables();
        $migration = require __DIR__.'/../../database/migrations/2026_01_01_000017_add_kinetix_team_fields_to_permission_tables.php';
        $migration->up();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Reset Eloquent's per-process guardable-columns memo (see
        // PermissionTeamScopingTest for the rationale).
        $guardable = new \ReflectionProperty(Model::class, 'guardableColumns');
        $guardable->setValue(null, []);

        app(PermissionRegistry::class)->feature('posts')->crud();
        foreach (app(PermissionRegistry::class)->allPermissions() as $name) {
            Permission::findOrCreate($name, 'web');
        }
        Permission::findOrCreate('roles.manage', 'web');
    }

    private function actingInTeamA(array $permissions = []): GuardedPermUser
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(self::TEAM_A);

        $manager = GuardedPermUser::create(['name' => 'Manager A']);
        $manager->givePermissionTo('roles.manage');

        foreach ($permissions as $permission) {
            $manager->givePermissionTo($permission);
        }

        $this->actingAs($manager);

        return $manager;
    }

    private function actingAsSuperAdmin(): GuardedPermUser
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);

        Role::findOrCreate('super-admin', 'web');
        $admin = GuardedPermUser::create(['name' => 'Root']);
        $admin->assignRole('super-admin');

        $registrar->setPermissionsTeamId(self::TEAM_A);
        $this->actingAs($admin);

        return $admin;
    }

    private function roleInTeam(?int $teamId, string $name, array $permissions = []): Role
    {
        $registrar = app(PermissionRegistrar::class);
        $previous  = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($teamId);

        $role = Role::create(['name' => $name, 'guard_name' => 'web']);

        if ($permissions !== []) {
            $role->syncPermissions($permissions);
        }

        $registrar->setPermissionsTeamId($previous);

        return $role;
    }

    private function assignInTeam(int $teamId, GuardedPermUser $user, string $role): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previous  = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($teamId);

        $user->assignRole($role);

        $registrar->setPermissionsTeamId($previous);
    }

    public function test_users_count_only_counts_the_current_teams_assignments(): void
    {
        // A GLOBAL role assigned to one user in team A and one in team B.
        $this->roleInTeam(null, 'editor');

        $inA = GuardedPermUser::create(['name' => 'In A']);
        $this->assignInTeam(self::TEAM_A, $inA, 'editor');

        $inB = GuardedPermUser::create(['name' => 'In B']);
        $this->assignInTeam(self::TEAM_B, $inB, 'editor');

        $this->actingInTeamA();

        $response = $this->getJson('/_kinetix/permissions/roles')->assertOk();

        $editor = collect($response->json())->firstWhere('name', 'editor');

        // Team A must see 1, not the cross-tenant total of 2.
        $this->assertSame(1, $editor['usersCount']);
    }

    public function test_deleting_a_role_still_in_use_is_rejected(): void
    {
        $role   = $this->roleInTeam(self::TEAM_A, 'writers');
        $member = GuardedPermUser::create(['name' => 'Member']);
        $this->assignInTeam(self::TEAM_A, $member, 'writers');

        $this->actingInTeamA();

        $this->deleteJson("/_kinetix/permissions/roles/{$role->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('roles', ['id' => $role->id]);

        // Once the last member is unassigned the delete goes through.
        $this->assignInTeam(self::TEAM_A, $member, 'writers'); // no-op, keeps context
        app(PermissionRegistrar::class)->setPermissionsTeamId(self::TEAM_A);
        $member->removeRole('writers');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->deleteJson("/_kinetix/permissions/roles/{$role->id}")
            ->assertOk();

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_a_super_admin_can_create_a_global_role(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->postJson('/_kinetix/permissions/roles', [
            'name'        => 'auditor',
            'permissions' => ['posts.view'],
            'global'      => true,
        ])->assertCreated();

        $this->assertTrue($response->json('isGlobal'));
        $this->assertDatabaseHas('roles', ['name' => 'auditor', 'team_id' => null]);
    }

    public function test_a_regular_manager_cannot_create_a_global_role(): void
    {
        $this->actingInTeamA(['posts.view']);

        $this->postJson('/_kinetix/permissions/roles', [
            'name'        => 'auditor',
            'permissions' => ['posts.view'],
            'global'      => true,
        ])->assertForbidden();

        $this->assertDatabaseMissing('roles', ['name' => 'auditor']);
    }

    public function test_a_manager_cannot_strip_permissions_they_do_not_hold(): void
    {
        // The role holds posts.delete; the manager holds only posts.view.
        $role = $this->roleInTeam(self::TEAM_A, 'writers', ['posts.delete', 'posts.view']);

        $this->actingInTeamA(['posts.view']);

        // Removing posts.delete is tampering with an ability they can't grant.
        $this->putJson("/_kinetix/permissions/roles/{$role->id}", [
            'permissions' => ['posts.view'],
        ])->assertForbidden();

        $this->assertTrue($role->fresh()->hasPermissionTo('posts.delete'));
    }

    public function test_a_rename_without_permission_changes_is_still_allowed(): void
    {
        $role = $this->roleInTeam(self::TEAM_A, 'writers', ['posts.delete']);

        $this->actingInTeamA(['posts.view']);

        // No permission delta — renaming does not require holding posts.delete.
        $this->putJson("/_kinetix/permissions/roles/{$role->id}", [
            'name' => 'authors',
        ])->assertOk();

        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'authors']);
    }

    public function test_the_assignable_roles_helper_unions_team_and_global_roles(): void
    {
        $this->roleInTeam(null, 'editor');                 // global
        $this->roleInTeam(null, 'super-admin');            // protected → excluded
        $this->roleInTeam(self::TEAM_A, 'writers');        // team A custom
        $this->roleInTeam(self::TEAM_B, 'other-team-role'); // invisible

        $names = AssignableRoles::names(self::TEAM_A);

        $this->assertSame(['editor', 'writers'], $names);

        // The `except` list withholds extra names (e.g. keep admin out of invites).
        $this->assertSame(['writers'], AssignableRoles::names(self::TEAM_A, ['editor']));
    }
}
