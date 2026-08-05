<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Membership\MemberProvision;
use Happones\Kinetix\Membership\MemberProvisionStatus;
use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Tests\Concerns\CreatesMembershipTables;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;

class LifecycleUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $guard_name = 'web';
}

/**
 * Membership state machine + team-context guarantees added in v0.140.0:
 * role changes on revoked members are rejected, resend can't resurrect
 * active/revoked provisions, cross-team provisions are untouchable, and the
 * role assignment on activation is pinned to the PROVISION's team even when
 * membership itself is not team-scoped.
 */
class MembershipLifecycleTest extends TestCase
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
        $app['config']->set('kinetix.membership.enabled', true);
        $app['config']->set('kinetix.membership.user_model', LifecycleUser::class);
        $app['config']->set('kinetix.membership.assignable_roles', ['editor', 'viewer']);

        // The mismatch that used to mis-scope assignments: membership itself
        // NOT team-scoped while spatie's team scoping is on. withTeam() must
        // key off spatie's flag and still pin the provision's team.
        $app['config']->set('kinetix.membership.teams', false);
        $app['config']->set('permission.teams', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMembershipTables();
        $migration = require __DIR__.'/../../database/migrations/2026_01_01_000017_add_kinetix_team_fields_to_permission_tables.php';
        $migration->up();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Reset Eloquent's per-process guardable-columns memo (see
        // PermissionTeamScopingTest for the rationale).
        $guardable = new \ReflectionProperty(Model::class, 'guardableColumns');
        $guardable->setValue(null, []);

        foreach (KinetixPermissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Global roles, like KinetixRolesSeeder produces.
        foreach (['editor', 'viewer'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function actingAsManager(): LifecycleUser
    {
        $user = LifecycleUser::create(['name' => 'Manager', 'email' => 'manager@example.com']);
        $user->givePermissionTo(['members.provision', 'members.viewAny', 'members.update', 'members.revoke']);

        $this->actingAs($user);

        return $user;
    }

    public function test_changing_a_members_role_swaps_the_assignment(): void
    {
        $member = LifecycleUser::create(['name' => 'Member', 'email' => 'member@example.com']);
        $member->assignRole('editor');

        $provision = MemberProvision::create([
            'email'   => 'member@example.com',
            'role'    => 'editor',
            'status'  => MemberProvisionStatus::Active,
            'user_id' => $member->id,
        ]);

        $this->actingAsManager();

        $this->putJson("/_kinetix/members/{$provision->id}", ['role' => 'viewer'])
            ->assertOk()
            ->assertJsonFragment(['role' => 'viewer']);

        $member = $member->fresh();
        $this->assertTrue($member->hasRole('viewer'));
        $this->assertFalse($member->hasRole('editor'));
    }

    public function test_a_role_change_on_a_revoked_member_is_rejected(): void
    {
        $member = LifecycleUser::create(['name' => 'Gone', 'email' => 'gone@example.com']);

        $provision = MemberProvision::create([
            'email'   => 'gone@example.com',
            'role'    => 'editor',
            'status'  => MemberProvisionStatus::Revoked,
            'user_id' => $member->id,
        ]);

        $this->actingAsManager();

        $this->putJson("/_kinetix/members/{$provision->id}", ['role' => 'viewer'])
            ->assertStatus(422);

        // No role was silently re-granted.
        $this->assertFalse($member->fresh()->hasRole('viewer'));
    }

    public function test_resend_cannot_resurrect_active_or_revoked_provisions(): void
    {
        Notification::fake();
        $this->actingAsManager();

        $active = MemberProvision::create([
            'email'  => 'active@example.com',
            'role'   => 'editor',
            'status' => MemberProvisionStatus::Active,
        ]);

        $revoked = MemberProvision::create([
            'email'  => 'revoked@example.com',
            'role'   => 'editor',
            'status' => MemberProvisionStatus::Revoked,
        ]);

        $pending = MemberProvision::create([
            'email'      => 'pending@example.com',
            'role'       => 'editor',
            'status'     => MemberProvisionStatus::Pending,
            'expires_at' => now()->subHour(), // expired pending is fine to resend
        ]);

        $this->postJson("/_kinetix/members/{$active->id}/resend")->assertStatus(422);
        $this->postJson("/_kinetix/members/{$revoked->id}/resend")->assertStatus(422);
        $this->postJson("/_kinetix/members/{$pending->id}/resend")->assertOk();

        $this->assertTrue($pending->fresh()->expires_at->isFuture());
    }

    public function test_activation_pins_the_role_assignment_to_the_provisions_team(): void
    {
        $provision = MemberProvision::create([
            'team_id'    => 7,
            'email'      => 'teamed@example.com',
            'role'       => 'editor',
            'status'     => MemberProvisionStatus::Pending,
            'expires_at' => now()->addDay(),
        ]);

        $url = URL::temporarySignedRoute(
            'kinetix.membership.activate.show',
            now()->addDay(),
            ['provision' => $provision->id],
        );

        $this->post($url, [
            'name'                  => 'Teamed User',
            'password'              => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertRedirect();

        $user = LifecycleUser::where('email', 'teamed@example.com')->firstOrFail();

        // Even with membership NOT team-scoped, the pivot row carries the
        // provision's team — never a stale/undefined registrar value.
        $this->assertDatabaseHas('model_has_roles', [
            'model_id' => $user->id,
            'team_id'  => 7,
        ]);
    }

    public function test_an_admin_cannot_touch_another_teams_provision(): void
    {
        // Manager operates teamless (membership.teams=false → whereNull scope);
        // a provision belonging to team 7 must be invisible to them.
        $foreign = MemberProvision::create([
            'team_id' => 7,
            'email'   => 'foreign@example.com',
            'role'    => 'editor',
            'status'  => MemberProvisionStatus::Pending,
        ]);

        $this->actingAsManager();

        $this->putJson("/_kinetix/members/{$foreign->id}", ['role' => 'viewer'])
            ->assertNotFound();
        $this->postJson("/_kinetix/members/{$foreign->id}/resend")
            ->assertNotFound();
        $this->deleteJson("/_kinetix/members/{$foreign->id}")
            ->assertNotFound();
    }
}
