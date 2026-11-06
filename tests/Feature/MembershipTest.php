<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Membership\KinetixMembership;
use Happones\Kinetix\Membership\MemberActivationNotification;
use Happones\Kinetix\Membership\MemberProvision;
use Happones\Kinetix\Membership\MemberProvisionStatus;
use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Tests\Concerns\CreatesMembershipTables;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;

class MemberUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $guard_name = 'web';
}

class MemberTeam extends Model
{
    protected $table = 'member_teams';

    public $timestamps = false;

    protected $guarded = [];
}

class MemberTeamUser extends MemberUser
{
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(MemberTeam::class, 'member_team_user', 'user_id', 'team_id');
    }
}

class MembershipTest extends TestCase
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
        $app['config']->set('kinetix.membership.user_model', MemberUser::class);
        $app['config']->set('kinetix.membership.assignable_roles', ['editor', 'viewer']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMembershipTables();

        // Materialize the member abilities so they can be granted to a manager.
        foreach (KinetixPermissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (['editor', 'viewer', 'admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function actingAsManager(): MemberUser
    {
        $user = MemberUser::create(['name' => 'Manager', 'email' => 'manager@example.com']);
        $user->givePermissionTo('members.provision');
        $user->givePermissionTo('members.viewAny');
        $user->givePermissionTo('members.update');
        $user->givePermissionTo('members.revoke');

        return $user;
    }

    public function test_admin_can_provision_a_member_and_an_activation_link_is_sent(): void
    {
        Notification::fake();

        $this->actingAs($this->actingAsManager())
            ->postJson('/_kinetix/members', ['email' => 'new@example.com', 'role' => 'editor'])
            ->assertCreated()
            ->assertJsonFragment(['email' => 'new@example.com', 'role' => 'editor', 'status' => 'pending']);

        $this->assertDatabaseHas('kinetix_member_provisions', [
            'email'  => 'new@example.com',
            'role'   => 'editor',
            'status' => 'pending',
        ]);

        Notification::assertSentOnDemand(MemberActivationNotification::class);
    }

    public function test_a_non_assignable_role_is_rejected(): void
    {
        $this->actingAs($this->actingAsManager())
            ->postJson('/_kinetix/members', ['email' => 'boss@example.com', 'role' => 'admin'])
            ->assertStatus(422);

        $this->assertDatabaseMissing('kinetix_member_provisions', ['email' => 'boss@example.com']);
    }

    public function test_users_without_permission_are_denied(): void
    {
        $nobody = MemberUser::create(['name' => 'Nobody', 'email' => 'nobody@example.com']);

        $this->actingAs($nobody)
            ->getJson('/_kinetix/members')
            ->assertForbidden();
    }

    public function test_member_activates_by_setting_a_password_and_gets_the_role(): void
    {
        $provision = MemberProvision::create([
            'email'      => 'jane@example.com',
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
            'name'                  => 'Jane Doe',
            'password'              => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertRedirect();

        $user = MemberUser::where('email', 'jane@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('editor'));

        $this->assertDatabaseHas('kinetix_member_provisions', [
            'id'      => $provision->id,
            'status'  => 'active',
            'user_id' => $user->id,
            'name'    => 'Jane Doe',
        ]);
    }

    public function test_activation_follows_the_apps_password_rules(): void
    {
        Password::defaults(fn () => Password::min(12)->mixedCase());

        try {
            $provision = MemberProvision::create([
                'email'      => 'weak@example.com',
                'role'       => 'editor',
                'status'     => MemberProvisionStatus::Pending,
                'expires_at' => now()->addDay(),
            ]);

            $url = URL::temporarySignedRoute('kinetix.membership.activate.show', now()->addDay(), ['provision' => $provision->id]);

            $this->post($url, [
                'name'                  => 'Weak',
                'password'              => 'abcdefgh',
                'password_confirmation' => 'abcdefgh',
            ])->assertSessionHasErrors('password');

            $this->assertNull(MemberUser::where('email', 'weak@example.com')->first());
        } finally {
            Password::$defaultCallback = null;
        }
    }

    /**
     * The set-password page says which business is asking — without it, it
     * reads like any form wanting a password.
     */
    public function test_the_activation_page_names_the_team_being_joined(): void
    {
        Schema::create('member_teams', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });
        config(['auth.providers.users.model' => MemberTeamUser::class]);
        $team = MemberTeam::create(['name' => 'Acme Coffee']);

        $joining = MemberProvision::create([
            'email'      => 'jane@example.com',
            'role'       => 'editor',
            'team_id'    => $team->id,
            'status'     => MemberProvisionStatus::Pending,
            'expires_at' => now()->addDay(),
        ]);
        $teamless = MemberProvision::create([
            'email'      => 'solo@example.com',
            'role'       => 'editor',
            'status'     => MemberProvisionStatus::Pending,
            'expires_at' => now()->addDay(),
        ]);

        $page = fn (MemberProvision $provision): array => $this->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Version', '1')
            ->get(URL::temporarySignedRoute('kinetix.membership.activate.show', now()->addDay(), ['provision' => $provision->id]))
            ->assertOk()
            ->json('props');

        $this->assertSame('Acme Coffee', $page($joining)['team']);
        $this->assertNull($page($teamless)['team']);
    }

    public function test_activation_link_rejected_once_consumed(): void
    {
        $provision = MemberProvision::create([
            'email'      => 'used@example.com',
            'role'       => 'editor',
            'status'     => MemberProvisionStatus::Active,
            'expires_at' => now()->addDay(),
        ]);

        $url = URL::temporarySignedRoute(
            'kinetix.membership.activate.show',
            now()->addDay(),
            ['provision' => $provision->id],
        );

        $this->post($url, [
            'name'                  => 'Late Comer',
            'password'              => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertStatus(410);
    }

    public function test_admin_can_revoke_a_member(): void
    {
        $member = MemberUser::create(['name' => 'Member', 'email' => 'member@example.com']);
        $member->assignRole('editor');

        $provision = MemberProvision::create([
            'email'   => 'member@example.com',
            'role'    => 'editor',
            'status'  => MemberProvisionStatus::Active,
            'user_id' => $member->id,
        ]);

        $this->actingAs($this->actingAsManager())
            ->deleteJson("/_kinetix/members/{$provision->id}")
            ->assertOk();

        $this->assertDatabaseHas('kinetix_member_provisions', [
            'id'     => $provision->id,
            'status' => 'revoked',
        ]);
        $this->assertFalse($member->fresh()->hasRole('editor'));
    }

    public function test_a_member_is_revoked_outside_a_request_and_revoking_twice_changes_nothing(): void
    {
        $detached = 0;
        config()->set('kinetix.membership.detach_member', function () use (&$detached): void {
            $detached++;
        });

        $member = MemberUser::create(['name' => 'Member', 'email' => 'member@example.com']);
        $member->assignRole('editor');

        $provision = MemberProvision::create([
            'email'   => 'member@example.com',
            'role'    => 'editor',
            'status'  => MemberProvisionStatus::Active,
            'user_id' => $member->id,
        ]);

        KinetixMembership::revoke($provision);
        KinetixMembership::revoke($provision->fresh());

        $this->assertSame(MemberProvisionStatus::Revoked, $provision->fresh()->status);
        $this->assertFalse($member->fresh()->hasRole('editor'));
        $this->assertSame(1, $detached);
    }

    public function test_re_provisioning_an_active_member_is_refused_and_leaves_the_row_alone(): void
    {
        Notification::fake();

        $member = MemberUser::create(['name' => 'Member', 'email' => 'member@example.com']);
        $member->assignRole('editor');

        $provision = MemberProvision::create([
            'email'        => 'member@example.com',
            'role'         => 'editor',
            'status'       => MemberProvisionStatus::Active,
            'user_id'      => $member->id,
            'activated_at' => now(),
        ]);

        $this->actingAs($this->actingAsManager())
            ->postJson('/_kinetix/members', ['email' => 'member@example.com', 'role' => 'viewer'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('kinetix.members_already_member'));

        $fresh = $provision->fresh();
        $this->assertSame(MemberProvisionStatus::Active, $fresh?->status);
        $this->assertSame($member->id, (int) $fresh?->user_id);
        $this->assertSame('editor', $fresh?->role);
    }

    public function test_re_provisioning_a_pending_invitation_still_refreshes_it(): void
    {
        Notification::fake();

        MemberProvision::create([
            'email'      => 'new@example.com',
            'role'       => 'editor',
            'status'     => MemberProvisionStatus::Pending,
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($this->actingAsManager())
            ->postJson('/_kinetix/members', ['email' => 'new@example.com', 'role' => 'viewer'])
            ->assertCreated();

        $this->assertSame(1, MemberProvision::query()->count());
        $this->assertSame('viewer', MemberProvision::query()->first()?->role);
    }

    public function test_the_doctor_flags_pending_provisions_of_existing_members(): void
    {
        MemberUser::create(['name' => 'Member', 'email' => 'member@example.com']);

        $stale = MemberProvision::create(['email' => 'member@example.com', 'role' => 'editor', 'status' => MemberProvisionStatus::Pending]);
        MemberProvision::create(['email' => 'new@example.com', 'role' => 'editor', 'status' => MemberProvisionStatus::Pending]);

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('1 pending provision(s) name someone who is already a member')
            ->expectsOutputToContain("#{$stale->id} member@example.com");
    }

    public function test_the_doctor_only_flags_a_team_provision_when_the_user_is_in_that_team(): void
    {
        config()->set('kinetix.membership.user_model', MemberTeamUser::class);

        Schema::create('member_teams', function (Blueprint $table) {
            $table->increments('id');
        });
        Schema::create('member_team_user', function (Blueprint $table) {
            $table->unsignedInteger('team_id');
            $table->unsignedInteger('user_id');
        });

        $team = MemberTeam::create();
        $user = MemberTeamUser::create(['name' => 'Someone', 'email' => 'someone@example.com']);

        MemberProvision::create(['team_id' => $team->id, 'email' => 'someone@example.com', 'role' => 'editor', 'status' => MemberProvisionStatus::Pending]);

        $this->artisan('kinetix:doctor')
            ->doesntExpectOutputToContain('pending provision(s) name someone who is already a member');

        $user->teams()->attach($team->id);

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('1 pending provision(s) name someone who is already a member');
    }

    public function test_the_doctor_is_quiet_without_such_provisions(): void
    {
        MemberProvision::create(['email' => 'new@example.com', 'role' => 'editor', 'status' => MemberProvisionStatus::Pending]);

        $this->artisan('kinetix:doctor')
            ->doesntExpectOutputToContain('pending provision(s) name someone who is already a member');
    }

    public function test_cancelling_an_invitation_never_touches_an_existing_user_with_that_email(): void
    {
        Notification::fake();
        MembershipDetachSpy::$detached = [];
        config()->set('kinetix.membership.detach_member', [MembershipDetachSpy::class, 'detach']);

        // An existing user who holds `editor` from outside the members screen.
        $victim = MemberUser::create(['name' => 'Victim', 'email' => 'victim@example.com']);
        $victim->assignRole('editor');

        $manager = $this->actingAsManager();

        $this->actingAs($manager)
            ->postJson('/_kinetix/members', ['email' => 'victim@example.com', 'role' => 'editor'])
            ->assertCreated();

        $provision = MemberProvision::query()->where('email', 'victim@example.com')->firstOrFail();

        $this->actingAs($manager)
            ->deleteJson("/_kinetix/members/{$provision->id}")
            ->assertOk();

        $this->assertSame(MemberProvisionStatus::Revoked, $provision->fresh()?->status);
        $this->assertTrue($victim->fresh()?->hasRole('editor'));
        $this->assertSame([], MembershipDetachSpy::$detached);
    }
}

/**
 * Records every `membership.detach_member` call.
 */
class MembershipDetachSpy
{
    /**
     * @var list<string|null>
     */
    public static array $detached = [];

    public static function detach(?Model $user, MemberProvision $provision): void
    {
        static::$detached[] = $user?->getAttribute('email');
    }
}
