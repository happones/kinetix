<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionServiceProvider;

class SyncPostResource extends Resource
{
    public static function permissionFeature(): ?string
    {
        return 'posts';
    }
}

class PermissionsSyncTest extends TestCase
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

        // spatie reads permissions through the cache; use the array store so it
        // doesn't require a `cache` database table in tests.
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('permissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type']);
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type']);
            $table->primary(['role_id', 'model_id', 'model_type']);
        });
    }

    public function test_sync_creates_declared_permissions(): void
    {
        KinetixPermissions::feature('billing')->ability('manage');
        KinetixPermissions::resource(SyncPostResource::class);

        $this->artisan('kinetix:permissions:sync')->assertSuccessful();

        $this->assertDatabaseHas('permissions', ['name' => 'billing.manage', 'guard_name' => 'web']);
        $this->assertDatabaseHas('permissions', ['name' => 'posts.create', 'guard_name' => 'web']);
        $this->assertDatabaseHas('permissions', ['name' => 'posts.delete', 'guard_name' => 'web']);
        // 1 billing + 5 posts CRUD
        $this->assertSame(6, Permission::count());
    }

    public function test_sync_is_idempotent(): void
    {
        KinetixPermissions::feature('billing')->ability('manage');

        $this->artisan('kinetix:permissions:sync')->assertSuccessful();
        $this->artisan('kinetix:permissions:sync')->assertSuccessful();

        $this->assertSame(1, Permission::where('name', 'billing.manage')->count());
    }

    public function test_prune_removes_permissions_absent_from_the_registry(): void
    {
        Permission::findOrCreate('legacy.old', 'web');
        KinetixPermissions::feature('billing')->ability('manage');

        $this->artisan('kinetix:permissions:sync', ['--prune' => true])->assertSuccessful();

        $this->assertDatabaseMissing('permissions', ['name' => 'legacy.old']);
        $this->assertDatabaseHas('permissions', ['name' => 'billing.manage']);
    }
}
