<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Membership\MemberProvision;
use Happones\Kinetix\Membership\MemberProvisionStatus;
use Happones\Kinetix\Membership\MembershipController;
use Happones\Kinetix\Permissions\AssignableRoles;
use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Permissions\KinetixRolesSeeder;
use Happones\Kinetix\Permissions\SuperAdmin;
use Happones\Kinetix\Permissions\TeamOwner;
use Happones\Kinetix\Tests\Concerns\CreatesMembershipTables;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;

class PlatformRoleTeam extends Model
{
    protected $table = 'pr_teams';

    public $timestamps = false;

    protected $guarded = [];
}

class PlatformRoleUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $guard_name = 'web';

    /**
     * @return BelongsToMany<PlatformRoleTeam, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(PlatformRoleTeam::class, 'pr_team_user', 'user_id', 'team_id');
    }

    public function ownsTeam(mixed $team): bool
    {
        return $team instanceof PlatformRoleTeam && (int) $team->owner_id === (int) $this->getKey();
    }
}

/**
 * Roles carrying a platform-level permission (`users.impersonate`) are
 * assignable through membership only by a super-admin. Otherwise a team owner
 * — who passes `members.update` through the owner bypass — could hand a
 * super-admin-built role to a second account and reach every tenant through it.
 */
class MembershipPlatformRolesTest extends TestCase
{
    use CreatesMembershipTables;

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
        $app['config']->set('kinetix.impersonation.enabled', true);
        $app['config']->set('kinetix.membership.enabled', true);
        $app['config']->set('kinetix.membership.user_model', PlatformRoleUser::class);
        $app['config']->set('kinetix.membership.assignable_roles', null);
        $app['config']->set('auth.providers.users.model', PlatformRoleUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMembershipTables();
        $migration = require __DIR__.'/../../database/migrations/2026_01_01_000017_add_kinetix_team_fields_to_permission_tables.php';
        $migration->up();

        Schema::create('pr_teams', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('owner_id');
        });

        Schema::create('pr_team_user', function (Blueprint $table) {
            $table->unsignedInteger('team_id');
            $table->unsignedInteger('user_id');
        });

        $guardable = new \ReflectionProperty(Model::class, 'guardableColumns');
        $guardable->setValue(null, []);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (KinetixPermissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Global roles: an ordinary one, and a super-admin-built one holding
        // the platform ability.
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        Role::findOrCreate('viewer', 'web')->givePermissionTo('members.viewAny');
        Role::findOrCreate('support', 'web')->givePermissionTo('users.impersonate');
        Role::findOrCreate('super-admin', 'web');

        TeamOwner::flush();
        SuperAdmin::flush();
    }

    protected function tearDown(): void
    {
        TeamOwner::flush();
        SuperAdmin::flush();

        parent::tearDown();
    }

    public function test_an_owner_cannot_assign_a_platform_role_and_its_reach_never_arrives(): void
    {
        config()->set('kinetix.impersonation.can_impersonate', null);

        [$owner, $team]           = $this->ownedTeam();
        [$accomplice, $provision] = $this->activeMember('Accomplice', $team);
        $victim                   = $this->teamUser('Victim', PlatformRoleTeam::create(['owner_id' => 999]));

        // A forged role change through the members endpoint is refused…
        $this->actingAs($owner)
            ->putJson("/{$team->id}/_kinetix/members/{$provision->id}", ['role' => 'support'])
            ->assertStatus(422);

        $this->assertSame('viewer', $provision->fresh()?->role);

        // …so the account never holds the platform ability, and can't use it
        // to impersonate a user of another tenant.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($accomplice->fresh())
            ->post("/{$team->id}/_kinetix/impersonate/{$victim->id}")
            ->assertForbidden();
    }

    public function test_an_owner_cannot_invite_with_a_platform_role(): void
    {
        Notification::fake();

        [$owner, $team] = $this->ownedTeam();

        $this->actingAs($owner)
            ->postJson("/{$team->id}/_kinetix/members", ['email' => 'helper@example.com', 'role' => 'support'])
            ->assertStatus(422);

        $this->assertDatabaseMissing('kinetix_member_provisions', ['email' => 'helper@example.com']);
    }

    public function test_the_picker_omits_platform_roles_for_an_owner_and_offers_them_to_a_super_admin(): void
    {
        [$owner, $team] = $this->ownedTeam();
        $root           = $this->superAdmin($team);

        // A TEAM role holding the platform ability is withheld the same way.
        app(PermissionRegistrar::class)->setPermissionsTeamId($team->id);
        Role::create(['name' => 'team-helpers', 'guard_name' => 'web'])->givePermissionTo('users.impersonate');

        $forOwner = $this->actingAs($owner)->getJson("/{$team->id}/_kinetix/members")->assertOk()->json('assignable_roles');

        $this->assertContains('viewer', $forOwner);
        $this->assertNotContains('support', $forOwner);
        $this->assertNotContains('team-helpers', $forOwner);

        $forRoot = $this->actingAs($root)->getJson("/{$team->id}/_kinetix/members")->assertOk()->json('assignable_roles');

        $this->assertContains('viewer', $forRoot);
        $this->assertContains('support', $forRoot);
        $this->assertContains('team-helpers', $forRoot);
    }

    public function test_a_static_allow_list_naming_a_platform_role_is_filtered_too(): void
    {
        config()->set('kinetix.membership.assignable_roles', ['viewer', 'support']);

        [$owner, $team] = $this->ownedTeam();

        $this->actingAs($owner)
            ->getJson("/{$team->id}/_kinetix/members")
            ->assertOk()
            ->assertJsonPath('assignable_roles', ['viewer']);
    }

    public function test_a_super_admin_can_assign_a_platform_role(): void
    {
        [, $team]             = $this->ownedTeam();
        $root                 = $this->superAdmin($team);
        [$helper, $provision] = $this->activeMember('Helper', $team);

        $this->actingAs($root)
            ->putJson("/{$team->id}/_kinetix/members/{$provision->id}", ['role' => 'support'])
            ->assertOk();

        app(PermissionRegistrar::class)->setPermissionsTeamId($team->id);

        $this->assertTrue($helper->fresh()?->hasRole('support'));
    }

    public function test_a_super_admin_invitation_with_a_platform_role_still_activates(): void
    {
        [, $team] = $this->ownedTeam();

        // The provisioner was a super-admin; the person activating is not, and
        // nobody is signed in. Activation re-checks the allow-list, not the actor.
        $provision = MemberProvision::create([
            'team_id'    => $team->id,
            'email'      => 'staff@example.com',
            'role'       => 'support',
            'status'     => MemberProvisionStatus::Pending,
            'expires_at' => now()->addDay(),
        ]);

        $url = URL::temporarySignedRoute('kinetix.membership.activate.show', now()->addDay(), ['provision' => $provision->id]);

        $this->post($url, [
            'name'                  => 'Staff',
            'password'              => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertRedirect();

        $this->assertSame(MemberProvisionStatus::Active, $provision->fresh()?->status);
    }

    public function test_platform_role_names_is_one_query_over_the_visible_roles(): void
    {
        [, $team] = $this->ownedTeam();

        app(PermissionRegistrar::class)->setPermissionsTeamId($team->id);
        Role::create(['name' => 'team-helpers', 'guard_name' => 'web'])->givePermissionTo('users.impersonate');

        $other = PlatformRoleTeam::create(['owner_id' => 999]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($other->id);
        Role::create(['name' => 'other-helpers', 'guard_name' => 'web'])->givePermissionTo('users.impersonate');

        DB::enableQueryLog();
        $names   = AssignableRoles::platformRoleNames($team->id);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertEqualsCanonicalizing(['support', 'team-helpers'], $names);
        $this->assertSame(1, $queries);
    }

    public function test_the_filter_skips_only_when_the_role_tables_are_missing(): void
    {
        config()->set('permission.table_names.role_has_permissions', 'not_migrated_yet');

        // Nothing stored can carry a platform permission, so nothing is removed.
        $this->assertSame(['viewer', 'support'], $this->filter(['viewer', 'support']));
    }

    public function test_the_filter_fails_closed_on_any_other_query_failure(): void
    {
        // A table that exists but is not the permissions table: the query
        // breaks, and a security filter must not read that as "nothing to
        // remove".
        config()->set('permission.table_names.permissions', 'pr_team_user');

        $this->expectException(QueryException::class);

        $this->filter(['viewer', 'support']);
    }

    public function test_the_seeded_presets_carry_no_platform_ability(): void
    {
        (new KinetixRolesSeeder)->run();

        foreach (['admin', 'editor'] as $preset) {
            $permissions = Role::where('name', $preset)->firstOrFail()->permissions->pluck('name')->all();

            $this->assertNotContains('users.impersonate', $permissions, "{$preset} must not carry a platform ability");
            $this->assertContains('members.update', $permissions);
        }
    }

    /**
     * `MembershipController::withoutPlatformRoles()` for a signed-out actor.
     *
     * @param  array<int, string> $roles
     * @return array<int, string>
     */
    private function filter(array $roles): array
    {
        $method = new \ReflectionMethod(MembershipController::class, 'withoutPlatformRoles');

        return $method->invoke(app(MembershipController::class), $roles, null);
    }

    /**
     * @return array{0: PlatformRoleUser, 1: PlatformRoleTeam}
     */
    private function ownedTeam(): array
    {
        $owner = PlatformRoleUser::create(['name' => 'Owner']);
        $team  = PlatformRoleTeam::create(['owner_id' => $owner->id]);
        $owner->teams()->attach($team->id);

        return [$owner, $team];
    }

    private function teamUser(string $name, PlatformRoleTeam $team): PlatformRoleUser
    {
        $user = PlatformRoleUser::create(['name' => $name]);
        $user->teams()->attach($team->id);

        return $user;
    }

    private function superAdmin(PlatformRoleTeam $team): PlatformRoleUser
    {
        $root = $this->teamUser('Root', $team);

        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $root->assignRole('super-admin');

        return $root;
    }

    /**
     * An active member of the team holding the ordinary `viewer` role.
     *
     * @return array{0: PlatformRoleUser, 1: MemberProvision}
     */
    private function activeMember(string $name, PlatformRoleTeam $team): array
    {
        $user = $this->teamUser($name, $team);

        app(PermissionRegistrar::class)->setPermissionsTeamId($team->id);
        $user->assignRole('viewer');

        $provision = MemberProvision::create([
            'team_id'      => $team->id,
            'email'        => strtolower($name).'@example.com',
            'user_id'      => $user->id,
            'role'         => 'viewer',
            'status'       => MemberProvisionStatus::Active,
            'activated_at' => now(),
        ]);

        return [$user, $provision];
    }
}
