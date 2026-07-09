<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Permissions\KinetixRolesSeeder;
use Happones\Kinetix\Tests\Concerns\CreatesPermissionTables;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
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
        $user->givePermissionTo('roles.manage');

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
