<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Membership\MemberActivationNotification;
use Happones\Kinetix\Membership\MemberProvision;
use Happones\Kinetix\Membership\MemberProvisionStatus;
use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
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

class MembershipTest extends TestCase
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
        $app['config']->set('kinetix.membership.enabled', true);
        $app['config']->set('kinetix.membership.user_model', MemberUser::class);
        $app['config']->set('kinetix.membership.assignable_roles', ['editor', 'viewer']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        // Materialize the member abilities so they can be granted to a manager.
        foreach (KinetixPermissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (['editor', 'viewer', 'admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function createTables(): void
    {
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
        });

        Schema::create('permissions', function ($table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function ($table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('role_has_permissions', function ($table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('model_has_permissions', function ($table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type']);
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });

        Schema::create('model_has_roles', function ($table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type']);
            $table->primary(['role_id', 'model_id', 'model_type']);
        });

        Schema::create('kinetix_member_provisions', function ($table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('role');
            $table->unsignedBigInteger('invited_by')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'email']);
        });
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
}
