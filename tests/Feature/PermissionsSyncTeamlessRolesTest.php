<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Tests\Concerns\CreatesPermissionTables;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;

class PermissionsSyncTeamlessRolesTest extends TestCase
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

        $this->createPermissionTables();
        $migration = require __DIR__.'/../../database/migrations/2026_01_01_000017_add_kinetix_team_fields_to_permission_tables.php';
        $migration->up();

        KinetixPermissions::feature('posts')->crud();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_it_lists_global_roles_that_should_probably_be_team_scoped(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('editor', 'web');

        $this->artisan('kinetix:permissions:sync')
            ->expectsOutputToContain('Global (teamless) roles found: admin, editor')
            ->assertSuccessful();
    }

    public function test_the_protected_roles_are_exempt(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        Role::findOrCreate('super-admin', 'web');

        $this->artisan('kinetix:permissions:sync')
            ->doesntExpectOutputToContain('Global (teamless) roles found')
            ->assertSuccessful();
    }

    public function test_team_scoped_roles_are_not_reported(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Role::findOrCreate('admin', 'web');

        $this->artisan('kinetix:permissions:sync')
            ->doesntExpectOutputToContain('Global (teamless) roles found')
            ->assertSuccessful();
    }

    public function test_nothing_is_reported_without_team_scoping(): void
    {
        config()->set('permission.teams', false);

        Role::findOrCreate('admin', 'web');

        $this->artisan('kinetix:permissions:sync')
            ->doesntExpectOutputToContain('Global (teamless) roles found')
            ->assertSuccessful();
    }
}
