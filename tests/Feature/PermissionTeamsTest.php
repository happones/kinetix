<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\Concerns\CreatesPermissionTables;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;

class TeamPermUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $guard_name = 'web';
}

class PermissionTeamsTest extends TestCase
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
        $app['config']->set('kinetix.permissions.enabled', true);
        $app['config']->set('kinetix.permissions.teams', true);
        $app['config']->set('permission.teams', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Stock (teamless) spatie tables + Kinetix's hybrid teams migration.
        $this->createPermissionTables();
        $migration = require __DIR__.'/../../database/migrations/2026_01_01_000017_add_kinetix_team_fields_to_permission_tables.php';
        $migration->up();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_the_hybrid_migration_adds_nullable_team_columns_outside_the_pk(): void
    {
        $this->assertTrue(Schema::hasColumn('roles', 'team_id'));
        $this->assertTrue(Schema::hasColumn('model_has_roles', 'team_id'));
        $this->assertTrue(Schema::hasColumn('model_has_permissions', 'team_id'));

        // Nullable outside the PK: a global (team NULL) assignment persists.
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);

        Role::create(['name' => 'super-admin', 'guard_name' => 'web']);
        TeamPermUser::create(['name' => 'Root'])->assignRole('super-admin');

        $this->assertDatabaseHas('model_has_roles', ['team_id' => null]);
    }

    public function test_a_teamless_super_admin_bypasses_gates_inside_any_team(): void
    {
        $registrar = app(PermissionRegistrar::class);

        // Assign super-admin globally (team NULL).
        $registrar->setPermissionsTeamId(null);
        Role::create(['name' => 'super-admin', 'guard_name' => 'web']);
        $root = TeamPermUser::create(['name' => 'Root']);
        $root->assignRole('super-admin');

        // Now act inside team 5 — hasRole() alone is team-scoped and misses…
        $registrar->setPermissionsTeamId(5);
        $root->unsetRelation('roles');

        // …but the Gate::before still honors the global assignment.
        $this->assertTrue(Gate::forUser($root)->allows('anything-at-all'));

        // And the registrar's team id is restored after the check.
        $this->assertSame(5, $registrar->getPermissionsTeamId());
    }

    public function test_a_team_scoped_super_admin_does_not_bypass_gates_in_other_teams(): void
    {
        $registrar = app(PermissionRegistrar::class);

        // Assign super-admin only inside team 1.
        $registrar->setPermissionsTeamId(1);
        Role::create(['name' => 'super-admin', 'guard_name' => 'web', 'team_id' => 1]);
        $admin = TeamPermUser::create(['name' => 'Team admin']);
        $admin->assignRole('super-admin');

        // Inside team 1 the bypass applies.
        $admin->unsetRelation('roles');
        $this->assertTrue(Gate::forUser($admin)->allows('anything-at-all'));

        // Inside team 2 it must not.
        $registrar->setPermissionsTeamId(2);
        $admin->unsetRelation('roles');
        $this->assertFalse(Gate::forUser($admin)->allows('anything-at-all'));
    }

    public function test_regular_users_are_not_granted_by_the_teamless_check(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(5);

        $user = TeamPermUser::create(['name' => 'Nobody']);

        $this->assertFalse(Gate::forUser($user)->allows('anything-at-all'));
    }
}
