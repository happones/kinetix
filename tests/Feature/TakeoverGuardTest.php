<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Impersonation\ImpersonationManager;
use Happones\Kinetix\Membership\MemberProvision;
use Happones\Kinetix\Membership\MemberProvisionStatus;
use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Permissions\SuperAdmin;
use Happones\Kinetix\Permissions\TeamOwner;
use Happones\Kinetix\Tests\Concerns\CreatesMembershipTables;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;

class TgTeam extends Model
{
    protected $table = 'tg_teams';

    public $timestamps = false;

    protected $guarded = [];
}

class TgUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $guard_name = 'web';

    /**
     * @return BelongsToMany<TgTeam, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(TgTeam::class, 'tg_team_user', 'user_id', 'team_id');
    }

    public function ownsTeam(mixed $team): bool
    {
        return $team instanceof TgTeam && (int) $team->owner_id === (int) $this->getKey();
    }
}

/**
 * A `can_impersonate` callback that narrows the built-in guards, as the
 * docs tell hosts to write it.
 */
class TgNarrowingImpersonation
{
    public static function allows(AuthenticatableContract $impersonator, AuthenticatableContract $target): bool
    {
        return app(ImpersonationManager::class)->passesBuiltInGuards($impersonator, $target);
    }
}

/**
 * Taking over a user — impersonating them, or issuing them a temporary
 * password from Members — is judged in every team the target belongs to:
 * the actor must share each of those teams, hold there every protected
 * permission the target holds, and own any team the target owns.
 */
class TakeoverGuardTest extends TestCase
{
    use CreatesMembershipTables;

    private TgTeam $teamA;

    private TgTeam $teamB;

    private TgUser $ownerA;

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
        $app['config']->set('kinetix.impersonation.can_impersonate', null);
        $app['config']->set('kinetix.membership.enabled', true);
        $app['config']->set('kinetix.membership.user_model', TgUser::class);
        $app['config']->set('kinetix.credentials.enabled', true);
        $app['config']->set('kinetix.credentials.user_model', TgUser::class);
        $app['config']->set('auth.providers.users.model', TgUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMembershipTables();
        (require __DIR__.'/../../database/migrations/2026_01_01_000017_add_kinetix_team_fields_to_permission_tables.php')->up();

        Schema::create('tg_teams', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('owner_id');
        });

        Schema::create('tg_team_user', function (Blueprint $table) {
            $table->unsignedInteger('team_id');
            $table->unsignedInteger('user_id');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (KinetixPermissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        Role::findOrCreate('super-admin', 'web');

        $this->ownerA = TgUser::create(['name' => 'Owner A']);
        $this->teamA  = TgTeam::create(['owner_id' => $this->ownerA->id]);
        $this->teamB  = TgTeam::create(['owner_id' => 999]);
        $this->ownerA->teams()->attach($this->teamA->id);

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
     * @param array<int, TgTeam> $teams
     */
    private function member(string $name, array $teams): TgUser
    {
        $user = TgUser::create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@example.com']);
        $user->teams()->attach(array_map(static fn (TgTeam $team): int => $team->id, $teams));

        return $user;
    }

    private function grant(TgUser $user, TgTeam $team, string ...$permissions): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($team->id);
        $user->givePermissionTo($permissions);
        $user->unsetRelation('permissions');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function superAdmin(TgUser $user): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $user->assignRole('super-admin');
        $user->unsetRelation('roles');
    }

    private function impersonate(TgUser $actor, TgUser $target, ?TgTeam $team = null): TestResponse
    {
        $team ??= $this->teamA;

        return $this->actingAs($actor)->post("/{$team->id}/_kinetix/impersonate/{$target->id}");
    }

    private function provisionFor(TgUser $user, TgTeam $team): MemberProvision
    {
        return MemberProvision::create([
            'team_id'      => $team->id,
            'email'        => $user->email,
            'user_id'      => $user->id,
            'role'         => 'viewer',
            'status'       => MemberProvisionStatus::Active,
            'activated_at' => now(),
        ]);
    }

    private function credential(TgUser $actor, MemberProvision $provision, ?TgTeam $team = null): TestResponse
    {
        $team ??= $this->teamA;

        return $this->actingAs($actor)->postJson("/{$team->id}/_kinetix/members/{$provision->id}/credential");
    }

    public function test_a_stored_impersonation_grant_does_not_reach_a_user_of_another_team(): void
    {
        $staff = $this->member('Staff', [$this->teamA]);
        $this->grant($staff, $this->teamA, 'users.impersonate');
        $stranger = $this->member('Stranger', [$this->teamB]);

        $this->impersonate($staff, $stranger)->assertForbidden();

        $this->assertSame($staff->id, auth()->id());
    }

    public function test_a_protected_permission_held_in_another_team_protects_the_target(): void
    {
        $staff = $this->member('Staff', [$this->teamA, $this->teamB]);
        $this->grant($staff, $this->teamA, 'users.impersonate');

        $target = $this->member('Target', [$this->teamA, $this->teamB]);
        $this->grant($target, $this->teamB, 'roles.manage');

        $this->impersonate($staff, $target)->assertForbidden();
    }

    public function test_a_team_owner_is_protected_from_a_non_owner(): void
    {
        $staff = $this->member('Staff', [$this->teamA]);
        $this->grant($staff, $this->teamA, 'users.impersonate');

        $this->impersonate($staff, $this->ownerA)->assertForbidden();
    }

    public function test_a_plain_member_of_a_shared_team_can_be_impersonated(): void
    {
        $staff = $this->member('Staff', [$this->teamA]);
        $this->grant($staff, $this->teamA, 'users.impersonate');
        $member = $this->member('Member', [$this->teamA]);

        $this->impersonate($staff, $member)->assertRedirect();

        $this->assertSame($member->id, auth()->id());
    }

    public function test_a_super_admin_crosses_teams(): void
    {
        $root = $this->member('Root', [$this->teamA]);
        $this->superAdmin($root);
        $stranger = $this->member('Stranger', [$this->teamB]);
        $this->grant($stranger, $this->teamB, 'roles.manage');

        $this->impersonate($root, $stranger)->assertRedirect();

        $this->assertSame($stranger->id, auth()->id());
    }

    public function test_a_callback_that_starts_with_the_built_in_guards_inherits_the_team_limit(): void
    {
        config()->set('kinetix.impersonation.can_impersonate', [TgNarrowingImpersonation::class, 'allows']);

        $staff = $this->member('Staff', [$this->teamA]);
        $this->grant($staff, $this->teamA, 'users.impersonate');
        $stranger = $this->member('Stranger', [$this->teamB]);

        $this->impersonate($staff, $stranger)->assertForbidden();
    }

    public function test_a_credential_is_refused_for_a_member_of_another_team_too(): void
    {
        $target = $this->member('Shared', [$this->teamA, $this->teamB]);

        $this->credential($this->ownerA, $this->provisionFor($target, $this->teamA))
            ->assertForbidden()
            ->assertJsonPath('message', __('kinetix.members_credential_forbidden'));

        $this->assertNull($target->fresh()?->password);
    }

    public function test_a_credential_is_refused_for_a_super_admin(): void
    {
        $target = $this->member('Root', [$this->teamA]);
        $this->superAdmin($target);

        $this->credential($this->ownerA, $this->provisionFor($target, $this->teamA))->assertForbidden();
    }

    public function test_a_credential_is_refused_for_a_target_holding_an_unheld_protected_permission(): void
    {
        $manager = $this->member('Manager', [$this->teamA]);
        $this->grant($manager, $this->teamA, 'members.credentials');

        $target = $this->member('Admin', [$this->teamA]);
        $this->grant($target, $this->teamA, 'roles.manage');

        $this->credential($manager, $this->provisionFor($target, $this->teamA))->assertForbidden();
    }

    public function test_a_credential_is_issued_for_a_plain_member(): void
    {
        $target = $this->member('Member', [$this->teamA]);

        $this->credential($this->ownerA, $this->provisionFor($target, $this->teamA))
            ->assertOk()
            ->assertJsonPath('credential.type', 'password');
    }

    public function test_with_the_owner_bypass_an_owner_may_issue_a_credential_to_their_own_admin(): void
    {
        $admin = $this->member('Admin', [$this->teamA]);
        $this->grant($admin, $this->teamA, 'roles.manage');

        $this->credential($this->ownerA, $this->provisionFor($admin, $this->teamA))->assertOk();
    }

    public function test_without_the_owner_bypass_ownership_grants_no_protected_permission(): void
    {
        config()->set('kinetix.permissions.owner_bypass', null);
        TeamOwner::flush();

        // The owner may issue credentials at all, through a stored grant…
        $this->grant($this->ownerA, $this->teamA, 'members.credentials');

        $admin = $this->member('Admin', [$this->teamA]);
        $this->grant($admin, $this->teamA, 'roles.manage');

        // …but owning the team doesn't stand in for roles.manage.
        $this->credential($this->ownerA, $this->provisionFor($admin, $this->teamA))
            ->assertForbidden()
            ->assertJsonPath('message', __('kinetix.members_credential_forbidden'));
    }

    public function test_a_protected_permission_the_owner_bypass_excludes_is_not_granted_by_ownership(): void
    {
        config()->set('kinetix.permissions.owner_bypass', ['using' => true, 'except' => ['roles.manage']]);
        TeamOwner::flush();

        $admin = $this->member('Admin', [$this->teamA]);
        $this->grant($admin, $this->teamA, 'roles.manage');

        $this->credential($this->ownerA, $this->provisionFor($admin, $this->teamA))
            ->assertForbidden()
            ->assertJsonPath('message', __('kinetix.members_credential_forbidden'));
    }
}
