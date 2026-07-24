<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

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

class ScopedPermUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $guard_name = 'web';
}

/**
 * Tenant isolation of the role-management endpoints under spatie team scoping:
 * another team's roles must be invisible (listing) and untouchable (404 on
 * update/delete), GLOBAL (team-NULL) roles are super-admin-only to modify, and
 * an existing name can never be silently taken over through store/rename.
 */
class PermissionTeamScopingTest extends TestCase
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
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPermissionTables();
        $migration = require __DIR__.'/../../database/migrations/2026_01_01_000017_add_kinetix_team_fields_to_permission_tables.php';
        $migration->up();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Eloquent memoizes each model's guardable columns per class for the
        // WHOLE phpunit process. Earlier test classes introspect `roles`
        // before the teams migration adds team_id, so spatie's stamped team id
        // would be silently discarded here. Test-only artifact — reset it.
        $guardable = new \ReflectionProperty(Model::class, 'guardableColumns');
        $guardable->setValue(null, []);

        app(PermissionRegistry::class)->feature('posts')->crud();
        foreach (app(PermissionRegistry::class)->allPermissions() as $name) {
            Permission::findOrCreate($name, 'web');
        }
        Permission::findOrCreate('roles.manage', 'web');
    }

    /**
     * A role-manager acting inside team A (permission context pinned there).
     */
    private function actingInTeamA(): ScopedPermUser
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(self::TEAM_A);

        $manager = ScopedPermUser::create(['name' => 'Manager A']);
        $manager->givePermissionTo('roles.manage');
        $manager->givePermissionTo(app(PermissionRegistry::class)->allPermissions());

        $this->actingAs($manager);

        return $manager;
    }

    private function roleInTeam(int $teamId, string $name): Role
    {
        $registrar = app(PermissionRegistrar::class);
        $previous  = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($teamId);

        $role = Role::create(['name' => $name, 'guard_name' => 'web']);

        $registrar->setPermissionsTeamId($previous);

        return $role;
    }

    public function test_the_listing_never_shows_another_teams_roles(): void
    {
        $this->actingInTeamA();

        $this->roleInTeam(self::TEAM_A, 'a-editors');
        $this->roleInTeam(self::TEAM_B, 'b-secret-role');
        Role::create(['name' => 'global-viewer', 'guard_name' => 'web', 'team_id' => null]);

        $names = collect($this->getJson('/_kinetix/permissions/roles')->json())->pluck('name');

        $this->assertTrue($names->contains('a-editors'));
        $this->assertTrue($names->contains('global-viewer'));
        $this->assertFalse($names->contains('b-secret-role'));
    }

    public function test_the_listing_marks_global_roles(): void
    {
        $this->actingInTeamA();

        $this->roleInTeam(self::TEAM_A, 'a-editors');
        Role::create(['name' => 'global-viewer', 'guard_name' => 'web', 'team_id' => null]);

        $roles = collect($this->getJson('/_kinetix/permissions/roles')->json())->keyBy('name');

        $this->assertTrue($roles['global-viewer']['isGlobal']);
        $this->assertFalse($roles['a-editors']['isGlobal']);
    }

    public function test_another_teams_role_cannot_be_updated_or_deleted(): void
    {
        $this->actingInTeamA();
        $foreign = $this->roleInTeam(self::TEAM_B, 'b-secret-role');

        // Invisible → indistinguishable from a missing id.
        $this->putJson("/_kinetix/permissions/roles/{$foreign->id}", ['name' => 'hijacked'])
            ->assertNotFound();
        $this->deleteJson("/_kinetix/permissions/roles/{$foreign->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('roles', ['id' => $foreign->id, 'name' => 'b-secret-role']);
    }

    public function test_a_global_role_is_only_modifiable_by_a_super_admin(): void
    {
        $this->actingInTeamA();
        $global = Role::create(['name' => 'global-viewer', 'guard_name' => 'web', 'team_id' => null]);

        // A per-team manager editing a global role would leak into every team.
        $this->putJson("/_kinetix/permissions/roles/{$global->id}", ['permissions' => ['posts.view']])
            ->assertForbidden();
        $this->deleteJson("/_kinetix/permissions/roles/{$global->id}")
            ->assertForbidden();

        // A (teamless) super-admin may.
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        Role::findOrCreate('super-admin', 'web');
        $root = ScopedPermUser::create(['name' => 'Root']);
        $root->assignRole('super-admin');
        $registrar->setPermissionsTeamId(self::TEAM_A);

        $this->actingAs($root)
            ->putJson("/_kinetix/permissions/roles/{$global->id}", ['permissions' => ['posts.view']])
            ->assertOk();
    }

    public function test_store_rejects_a_name_that_already_exists_in_scope(): void
    {
        $this->actingInTeamA();

        Role::create(['name' => 'global-viewer', 'guard_name' => 'web', 'team_id' => null]);
        $this->roleInTeam(self::TEAM_A, 'a-editors');

        // Neither a global role nor a same-team role can be silently re-synced
        // through "create" — both are validation errors now.
        $this->postJson('/_kinetix/permissions/roles', ['name' => 'global-viewer', 'permissions' => ['posts.view']])
            ->assertUnprocessable();
        $this->postJson('/_kinetix/permissions/roles', ['name' => 'a-editors', 'permissions' => ['posts.view']])
            ->assertUnprocessable();

        // The global role's permission set was not touched.
        $this->assertSame([], Role::findByName('global-viewer', 'web')->permissions->pluck('name')->all());
    }

    public function test_store_creates_a_team_scoped_role_and_allows_reusing_another_teams_name(): void
    {
        $this->actingInTeamA();
        $this->roleInTeam(self::TEAM_B, 'editors');

        // Same name in ANOTHER team is fine — roles are per-team rows.
        $this->postJson('/_kinetix/permissions/roles', ['name' => 'editors', 'permissions' => ['posts.view']])
            ->assertCreated();

        $this->assertDatabaseHas('roles', ['name' => 'editors', 'team_id' => self::TEAM_A]);
        $this->assertDatabaseHas('roles', ['name' => 'editors', 'team_id' => self::TEAM_B]);
    }

    public function test_rename_cannot_take_over_an_existing_name(): void
    {
        $this->actingInTeamA();

        $mine = $this->roleInTeam(self::TEAM_A, 'a-editors');
        Role::create(['name' => 'global-viewer', 'guard_name' => 'web', 'team_id' => null]);

        $this->putJson("/_kinetix/permissions/roles/{$mine->id}", ['name' => 'global-viewer'])
            ->assertUnprocessable();
    }
}
