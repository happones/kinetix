<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Permissions\PermissionRegistry;
use Happones\Kinetix\Permissions\SuperAdmin;
use Happones\Kinetix\Permissions\TeamOwner;
use Happones\Kinetix\Tests\Concerns\CreatesPermissionTables;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;

class AppWideTeam extends Model
{
    protected $table = 'aw_teams';

    public $timestamps = false;

    protected $guarded = [];
}

class AppWideUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $guard_name = 'web';

    /**
     * @return BelongsToMany<AppWideTeam, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(AppWideTeam::class, 'aw_team_user', 'user_id', 'team_id');
    }

    public function ownsTeam(mixed $team): bool
    {
        return $team instanceof AppWideTeam && (int) $team->owner_id === (int) $this->getKey();
    }
}

/**
 * A module kept app-wide (`kinetix.{module}.teams` = false) while the app has
 * teams holds one list every team shares: its abilities are platform-level,
 * so the team-owner bypass never grants them.
 */
class AppWideModulesTest extends TestCase
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
        $app['config']->set('kinetix.teams', true);
        $app['config']->set('permission.teams', true);
        $app['config']->set('kinetix.permissions.enabled', true);
        $app['config']->set('kinetix.permissions.owner_bypass', ['using' => true]);
        $app['config']->set('kinetix.webhooks.enabled', true);
        $app['config']->set('kinetix.webhooks.driver', 'native');
        // Null (follow the teams) for the one test that checks that case: the
        // flag is read when the provider boots.
        $app['config']->set('kinetix.webhooks.teams', $this->name() === 'test_a_team_scoped_module_is_not_platform_level' ? null : false);
        $app['config']->set('kinetix.settings.enabled', true);
        $app['config']->set('kinetix.settings.teams', false);
        $app['config']->set('kinetix.activity.enabled', true);
        $app['config']->set('auth.providers.users.model', AppWideUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPermissionTables();
        $migration = require __DIR__.'/../../database/migrations/2026_01_01_000017_add_kinetix_team_fields_to_permission_tables.php';
        $migration->up();

        Schema::create('aw_teams', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('owner_id');
        });

        Schema::create('aw_team_user', function (Blueprint $table) {
            $table->unsignedInteger('team_id');
            $table->unsignedInteger('user_id');
        });

        Schema::create('kinetix_webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('name');
            $table->string('url');
            $table->string('secret');
            $table->json('events');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (KinetixPermissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        TeamOwner::flush();
        SuperAdmin::flush();
    }

    protected function tearDown(): void
    {
        TeamOwner::flush();
        SuperAdmin::flush();

        parent::tearDown();
    }

    /**
     * @return array{0: AppWideUser, 1: AppWideTeam}
     */
    private function ownedTeam(): array
    {
        $owner = AppWideUser::create(['name' => 'Owner']);
        $team  = AppWideTeam::create(['owner_id' => $owner->id]);
        $owner->teams()->attach($team->id);

        return [$owner, $team];
    }

    public function test_app_wide_modules_are_platform_level(): void
    {
        $registry = app(PermissionRegistry::class);

        $this->assertTrue($registry->isPlatform('webhooks.manage'));
        $this->assertTrue($registry->isPlatform('settings.manage'));
        // Activity follows the teams (its own `teams` is null).
        $this->assertFalse($registry->isPlatform('activity.view'));
    }

    public function test_a_team_scoped_module_is_not_platform_level(): void
    {
        $this->assertFalse(app(PermissionRegistry::class)->isPlatform('webhooks.manage'));
    }

    public function test_a_team_owner_cannot_manage_the_shared_webhooks_and_a_super_admin_can(): void
    {
        [$owner, $team] = $this->ownedTeam();

        app(PermissionRegistrar::class)->setPermissionsTeamId($team->id);
        $this->assertFalse(Gate::forUser($owner)->allows('webhooks.manage'));
        // A team-scoped feature still follows ownership.
        $this->assertTrue(Gate::forUser($owner)->allows('activity.view'));

        $this->actingAs($owner)->getJson("/{$team->id}/_kinetix/webhooks")->assertForbidden();

        $root = AppWideUser::create(['name' => 'Root']);
        $root->teams()->attach($team->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        Role::findOrCreate('super-admin', 'web');
        $root->assignRole('super-admin');
        SuperAdmin::flush();

        $this->actingAs($root)->getJson("/{$team->id}/_kinetix/webhooks")->assertOk();
    }
}
