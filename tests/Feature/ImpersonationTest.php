<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Impersonation\ImpersonationManager;
use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;

class ImpUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $guard_name = 'web';
}

class ImpersonationTest extends TestCase
{
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
        $app['config']->set('kinetix.impersonation.enabled', true);
        $app['config']->set('auth.providers.users.model', ImpUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        foreach (['permissions', 'roles'] as $name) {
            Schema::create($name, function ($table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
        }

        Schema::create('role_has_permissions', function ($table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('model_has_permissions', function ($table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });

        Schema::create('model_has_roles', function ($table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });

        foreach (KinetixPermissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (['super-admin', 'admin', 'viewer'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function user(string $name, ?string $role = null): ImpUser
    {
        $user = ImpUser::create(['name' => $name]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }

    public function test_start_swaps_the_user_and_stop_restores_it(): void
    {
        $admin  = $this->user('Admin', 'admin');
        $viewer = $this->user('Viewer', 'viewer');

        $this->actingAs($admin);
        $manager = app(ImpersonationManager::class);

        $manager->start($viewer);
        $this->assertTrue($manager->isImpersonating());
        $this->assertSame($viewer->id, auth()->id());
        $this->assertSame($admin->id, $manager->impersonatorId());

        $manager->stop();
        $this->assertFalse($manager->isImpersonating());
        $this->assertSame($admin->id, auth()->id());
    }

    public function test_escalation_guard(): void
    {
        $manager = app(ImpersonationManager::class);

        $super  = $this->user('Super', 'super-admin');
        $admin  = $this->user('Admin', 'admin');
        $viewer = $this->user('Viewer', 'viewer');

        $this->assertFalse($manager->canImpersonate($admin, $super), 'admin cannot impersonate super-admin');
        $this->assertTrue($manager->canImpersonate($super, $admin), 'super-admin can impersonate admin');
        $this->assertTrue($manager->canImpersonate($admin, $viewer), 'admin can impersonate viewer');
        $this->assertFalse($manager->canImpersonate($admin, $admin), 'cannot impersonate self');
    }

    public function test_start_endpoint_is_gated_by_the_ability(): void
    {
        $viewer = $this->user('Viewer', 'viewer');

        $manager = $this->user('Manager', 'admin');
        $manager->givePermissionTo('users.impersonate');

        $this->actingAs($manager)
            ->post('/_kinetix/impersonate/'.$viewer->id)
            ->assertRedirect();

        $this->actingAs($this->user('Nobody'))
            ->post('/_kinetix/impersonate/'.$viewer->id)
            ->assertForbidden();
    }

    public function test_protect_middleware_blocks_sensitive_routes_while_impersonating(): void
    {
        Route::middleware('kinetix.impersonation.protect')->get('/_test/sensitive', fn () => 'ok');

        $admin  = $this->user('Admin', 'admin');
        $viewer = $this->user('Viewer', 'viewer');

        $this->actingAs($admin);
        $this->get('/_test/sensitive')->assertOk();

        app(ImpersonationManager::class)->start($viewer);
        $this->get('/_test/sensitive')->assertForbidden();
    }
}
