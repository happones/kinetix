<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Impersonation\ImpersonationManager;
use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Permissions\SuperAdmin;
use Happones\Kinetix\Permissions\TeamOwner;
use Happones\Kinetix\Support\KinetixTeams;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;

class ImpUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $guard_name = 'web';

    /**
     * The host's team membership (the `imp_team_user` table exists only under
     * the `withOwnedTeams` environment).
     *
     * @return BelongsToMany<ImpTeam, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(ImpTeam::class, 'imp_team_user', 'user_id', 'team_id');
    }

    public function ownsTeam(mixed $team): bool
    {
        return $team instanceof ImpTeam && (int) $team->owner_id === (int) $this->getKey();
    }
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

        // Team-scoped permission tables + the host's teams, for the
        // owner-impersonation recipe below.
        if (config('permission.teams', false)) {
            $migration = require __DIR__.'/../../database/migrations/2026_01_01_000017_add_kinetix_team_fields_to_permission_tables.php';
            $migration->up();

            Schema::create('imp_teams', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('owner_id');
            });

            Schema::create('imp_team_user', function (Blueprint $table) {
                $table->unsignedInteger('team_id');
                $table->unsignedInteger('user_id');
            });
        }

        foreach (KinetixPermissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (['super-admin', 'admin', 'viewer'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    /**
     * The permission layer plus the team-owner bypass, on at boot (where the
     * owner Gate::before is registered).
     *
     * @param Application $app
     */
    protected function withOwnerBypass($app): void
    {
        $app['config']->set('kinetix.permissions.enabled', true);
        $app['config']->set('kinetix.permissions.owner_bypass', ['using' => [ImpOwnership::class, 'owns']]);
    }

    /**
     * Teams on everywhere (the `{current_team}` segment, team-scoped spatie
     * tables) with the owner bypass — the setup the owner-impersonation recipe
     * in docs/permissions.md is written for.
     *
     * @param Application $app
     */
    protected function withOwnedTeams($app): void
    {
        $app['config']->set('kinetix.teams', true);
        $app['config']->set('permission.teams', true);
        $app['config']->set('kinetix.permissions.enabled', true);
        $app['config']->set('kinetix.permissions.owner_bypass', ['using' => true]);
        $app['config']->set('kinetix.impersonation.can_impersonate', [OwnerImpersonation::class, 'sameTeam']);
    }

    protected function tearDown(): void
    {
        TeamOwner::flush();
        SuperAdmin::flush();

        parent::tearDown();
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

    #[DefineEnvironment('withOwnerBypass')]
    public function test_a_team_owner_does_not_get_impersonation_from_the_owner_bypass(): void
    {
        // `users.impersonate` resolves its target by GLOBAL id, so an owner
        // bypass that granted it would let the owner of one tenant log in as
        // the users of another. It is platform-level: never part of the grant.
        $owner  = $this->user('Owner');
        $viewer = $this->user('Viewer', 'viewer');

        $this->assertTrue(TeamOwner::check($owner));
        $this->assertTrue(Gate::forUser($owner)->allows('roles.manage'));

        $this->actingAs($owner)
            ->post('/_kinetix/impersonate/'.$viewer->id)
            ->assertForbidden();
    }

    #[DefineEnvironment('withOwnedTeams')]
    public function test_the_recipe_lets_an_owner_impersonate_a_member_of_their_team(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $member         = $this->teamUser('Member', $team);

        $this->actingAs($owner)
            ->post("/{$team->id}/_kinetix/impersonate/{$member->id}")
            ->assertRedirect();

        $this->assertSame($member->id, auth()->id());
    }

    #[DefineEnvironment('withOwnedTeams')]
    public function test_the_recipe_refuses_an_owner_a_member_of_another_team(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $stranger       = $this->teamUser('Stranger', ImpTeam::create(['owner_id' => 999]));

        $this->actingAs($owner)
            ->post("/{$team->id}/_kinetix/impersonate/{$stranger->id}")
            ->assertForbidden();
    }

    #[DefineEnvironment('withOwnedTeams')]
    public function test_the_recipe_refuses_an_owner_a_super_admin_of_their_own_team(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $root           = $this->teamUser('Root', $team);

        // Platform-wide (teamless) — the assignment a bare team-scoped
        // hasRole() would miss inside the owner's team.
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $root->assignRole('super-admin');

        $this->actingAs($owner)
            ->post("/{$team->id}/_kinetix/impersonate/{$root->id}")
            ->assertForbidden();
    }

    #[DefineEnvironment('withOwnedTeams')]
    public function test_the_recipe_keeps_a_team_scoped_grant_inside_the_team(): void
    {
        [, $team] = $this->ownedTeam();
        $staff    = $this->teamUser('Staff', $team);
        $member   = $this->teamUser('Member', $team);
        $stranger = $this->teamUser('Stranger', ImpTeam::create(['owner_id' => 999]));

        // A stored permission is itself team-scoped: it is not "platform
        // staff", so it reaches the team in the URL and nothing else.
        app(PermissionRegistrar::class)->setPermissionsTeamId($team->id);
        $staff->givePermissionTo('users.impersonate');

        $this->actingAs($staff)
            ->post("/{$team->id}/_kinetix/impersonate/{$stranger->id}")
            ->assertForbidden();

        $this->actingAs($staff)
            ->post("/{$team->id}/_kinetix/impersonate/{$member->id}")
            ->assertRedirect();

        $this->assertSame($member->id, auth()->id());
    }

    #[DefineEnvironment('withOwnedTeams')]
    public function test_the_recipe_lets_a_super_admin_cross_teams(): void
    {
        [, $team] = $this->ownedTeam();
        $root     = $this->teamUser('Root', $team);
        $stranger = $this->teamUser('Stranger', ImpTeam::create(['owner_id' => 999]));

        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $root->assignRole('super-admin');

        $this->actingAs($root)
            ->post("/{$team->id}/_kinetix/impersonate/{$stranger->id}")
            ->assertRedirect();

        $this->assertSame($stranger->id, auth()->id());
    }

    #[DefineEnvironment('withOwnedTeams')]
    public function test_an_owner_with_the_recipe_cannot_put_users_impersonate_in_a_role(): void
    {
        // The recipe's Gate::define makes the owner pass `users.impersonate`,
        // and the role guard asks the Gate — but a platform ability is
        // super-admin-only to grant, whatever the Gate says.
        [$owner, $team] = $this->ownedTeam();

        $this->actingAs($owner)
            ->postJson("/{$team->id}/_kinetix/permissions/roles", ['name' => 'helpers', 'permissions' => ['users.impersonate']])
            ->assertForbidden();

        app(PermissionRegistrar::class)->setPermissionsTeamId($team->id);
        $role = Role::create(['name' => 'support', 'guard_name' => 'web']);

        $this->actingAs($owner)
            ->putJson("/{$team->id}/_kinetix/permissions/roles/{$role->id}", ['permissions' => ['users.impersonate']])
            ->assertForbidden();

        $this->assertSame([], $role->fresh()?->permissions->pluck('name')->all());
    }

    #[DefineEnvironment('withOwnedTeams')]
    public function test_an_owner_cannot_launder_impersonation_into_other_tenants_through_a_role(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $accomplice     = $this->teamUser('Accomplice', $team);
        $member         = $this->teamUser('Member', $team);
        $victim         = $this->teamUser('Victim', ImpTeam::create(['owner_id' => 999]));

        // 1. The owner cannot create the role themselves…
        $this->actingAs($owner)
            ->postJson("/{$team->id}/_kinetix/permissions/roles", ['name' => 'helpers', 'permissions' => ['users.impersonate']])
            ->assertForbidden();

        // 2. …and even a team role a super-admin made holding it…
        $root = $this->teamUser('Root', $team);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $root->assignRole('super-admin');

        $this->actingAs($root)
            ->postJson("/{$team->id}/_kinetix/permissions/roles", ['name' => 'helpers', 'permissions' => ['users.impersonate']])
            ->assertCreated();

        app(PermissionRegistrar::class)->setPermissionsTeamId($team->id);
        $accomplice->assignRole(Role::where('name', 'helpers')->firstOrFail());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 3. …gives its holder no reach outside the team in the URL.
        $this->actingAs($accomplice)
            ->post("/{$team->id}/_kinetix/impersonate/{$victim->id}")
            ->assertForbidden();

        $this->actingAs($accomplice)
            ->post("/{$team->id}/_kinetix/impersonate/{$member->id}")
            ->assertRedirect();

        $this->assertSame($member->id, auth()->id());
    }

    #[DefineEnvironment('withOwnedTeams')]
    public function test_the_built_in_guard_counts_a_teamless_super_admin_inside_a_team(): void
    {
        config()->set('kinetix.impersonation.can_impersonate', null);

        [, $team] = $this->ownedTeam();
        $admin    = $this->teamUser('Admin', $team);
        $root     = $this->teamUser('Root', $team);

        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $root->assignRole('super-admin');

        // A request scoped to the team: a bare hasRole() would not see the
        // platform-wide assignment from here.
        app(PermissionRegistrar::class)->setPermissionsTeamId($team->id);

        $this->assertFalse(app(ImpersonationManager::class)->canImpersonate($admin, $root));
    }

    /**
     * @return array{0: ImpUser, 1: ImpTeam}
     */
    private function ownedTeam(): array
    {
        $owner = ImpUser::create(['name' => 'Owner']);
        $team  = ImpTeam::create(['owner_id' => $owner->id]);
        $owner->teams()->attach($team->id);

        // The recipe's Gate::define lives in the host's provider.
        Gate::define('users.impersonate', [OwnerImpersonation::class, 'allows']);

        return [$owner, $team];
    }

    private function teamUser(string $name, ImpTeam $team): ImpUser
    {
        $user = ImpUser::create(['name' => $name]);
        $user->teams()->attach($team->id);

        return $user;
    }

    public function test_the_redirects_can_be_decided_per_user(): void
    {
        config()->set('kinetix.impersonation.redirect_to', [ImpersonationRedirects::class, 'target']);
        config()->set('kinetix.impersonation.redirect_back', [ImpersonationRedirects::class, 'admin']);

        $viewer  = $this->user('Viewer', 'viewer');
        $manager = $this->user('Manager', 'admin');
        $manager->givePermissionTo('users.impersonate');

        $this->actingAs($manager)
            ->post('/_kinetix/impersonate/'.$viewer->id)
            ->assertRedirect('/as/Viewer');

        $this->delete('/_kinetix/impersonate')
            ->assertRedirect('/back/Manager');
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

    public function test_protect_writes_lets_the_page_show_but_blocks_changes_while_impersonating(): void
    {
        Route::middleware('kinetix.impersonation.protect:writes')->group(function (): void {
            Route::get('/_test/billing', fn () => 'plan');
            Route::post('/_test/billing/subscribe', fn () => 'subscribed');
        });

        $admin  = $this->user('Admin', 'admin');
        $viewer = $this->user('Viewer', 'viewer');

        $this->actingAs($admin);
        $this->post('/_test/billing/subscribe')->assertOk();

        app(ImpersonationManager::class)->start($viewer);
        $this->get('/_test/billing')->assertOk();
        $this->post('/_test/billing/subscribe')->assertForbidden();
    }
}

class ImpersonationRedirects
{
    public static function target(ImpUser $user): string
    {
        return '/as/'.$user->name;
    }

    public static function admin(ImpUser $user): string
    {
        return '/back/'.$user->name;
    }
}

/**
 * Stand-in for a host's team-ownership rule (a callable array, so the config
 * stays cacheable).
 */
class ImpOwnership
{
    public static function owns(mixed $user, mixed $team = null): bool
    {
        return $user instanceof ImpUser && $user->name === 'Owner';
    }
}

class ImpTeam extends Model
{
    protected $table = 'imp_teams';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * The owner-impersonation recipe from docs/permissions.md ("Platform abilities
 * and the impersonation recipe"), verbatim apart from the namespace.
 */
class OwnerImpersonation
{
    /**
     * `Gate::define('users.impersonate', …)`: owners of the team in the URL.
     */
    public function allows(AuthenticatableContract $user): bool
    {
        return TeamOwner::check($user);
    }

    /**
     * `impersonation.can_impersonate`. It REPLACES the built-in guards, so it
     * runs them first and only ever narrows their verdict. Only a super-admin
     * crosses teams.
     */
    public static function sameTeam(AuthenticatableContract $impersonator, AuthenticatableContract $target): bool
    {
        // No super-admin target unless you are one; no target holding a
        // protected permission (`roles.manage`) you don't hold.
        if (! app(ImpersonationManager::class)->passesBuiltInGuards($impersonator, $target)) {
            return false;
        }

        // A super-admin is the only impersonator who crosses teams.
        if (SuperAdmin::check($impersonator)) {
            return true;
        }

        // Everyone else (an owner, or a stored permission or role, which is
        // itself team-scoped) stays inside the team in the URL.
        $team = KinetixTeams::currentTeamKey();

        return $team !== null && $target instanceof Model && KinetixTeams::userBelongsTo($target, $team);
    }
}
