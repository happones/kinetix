<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Membership\MemberProvision;
use Happones\Kinetix\Membership\MemberProvisionStatus;
use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Tests\Concerns\CreatesMembershipTables;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;

class DynamicRolesUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $guard_name = 'web';
}

/**
 * The allow-list as an invokable class — the `config:cache`-safe way to express
 * a callback in a config file.
 */
class TeamScopedRoles
{
    /**
     * @return array<int, string>
     */
    public function __invoke(int|string|null $teamId): array
    {
        return $teamId === null ? [] : ['team-'.$teamId.'-editor'];
    }
}

class MembershipAssignableRolesTest extends TestCase
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
        $app['config']->set('kinetix.membership.user_model', DynamicRolesUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMembershipTables();

        foreach (KinetixPermissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (['editor', 'viewer', 'admin', 'team-7-editor'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_a_closure_resolves_the_allow_list_at_request_time(): void
    {
        config()->set('kinetix.membership.assignable_roles', fn ($teamId) => ['fresh-role', 'editor']);

        $this->actingAs($this->manager())
            ->getJson('/_kinetix/members')
            ->assertOk()
            ->assertJsonPath('assignable_roles', ['fresh-role', 'editor']);
    }

    public function test_a_closure_result_gates_provisioning(): void
    {
        Notification::fake();

        config()->set('kinetix.membership.assignable_roles', fn ($teamId) => ['editor']);

        $this->actingAs($this->manager())
            ->postJson('/_kinetix/members', ['email' => 'ok@example.com', 'role' => 'editor'])
            ->assertCreated();

        $this->actingAs($this->manager())
            ->postJson('/_kinetix/members', ['email' => 'nope@example.com', 'role' => 'viewer'])
            ->assertStatus(422);
    }

    public function test_an_eloquent_collection_of_roles_is_accepted(): void
    {
        config()->set(
            'kinetix.membership.assignable_roles',
            fn ($teamId) => Role::query()->whereIn('name', ['editor', 'viewer'])->get(),
        );

        $this->actingAs($this->manager())
            ->getJson('/_kinetix/members')
            ->assertOk()
            ->assertJsonPath('assignable_roles', ['editor', 'viewer']);
    }

    public function test_an_invokable_class_string_is_resolved_per_team(): void
    {
        config()->set('kinetix.membership.assignable_roles', TeamScopedRoles::class);

        $this->actingAs($this->manager())
            ->getJson('/_kinetix/members')
            ->assertOk()
            ->assertJsonPath('assignable_roles', []);   // no team context → nothing assignable
    }

    public function test_activation_validates_against_the_provisions_team_not_the_request(): void
    {
        // The signed activation URL carries no {current_team} segment, so a
        // per-team allow-list must be resolved from the provision itself.
        config()->set('kinetix.membership.assignable_roles', TeamScopedRoles::class);

        $provision = MemberProvision::create([
            'team_id'    => 7,
            'email'      => 'seven@example.com',
            'role'       => 'team-7-editor',
            'status'     => MemberProvisionStatus::Pending,
            'expires_at' => now()->addDay(),
        ]);

        $this->post($this->activationUrl($provision), [
            'name'                  => 'Seven',
            'password'              => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertRedirect();

        $this->assertDatabaseHas('kinetix_member_provisions', [
            'id'     => $provision->id,
            'status' => 'active',
        ]);
    }

    public function test_activation_is_refused_when_the_role_left_the_teams_allow_list(): void
    {
        config()->set('kinetix.membership.assignable_roles', TeamScopedRoles::class);

        $provision = MemberProvision::create([
            'team_id'    => 8,   // the callback only allows team-8-editor
            'email'      => 'eight@example.com',
            'role'       => 'team-7-editor',
            'status'     => MemberProvisionStatus::Pending,
            'expires_at' => now()->addDay(),
        ]);

        $this->post($this->activationUrl($provision), [
            'name'                  => 'Eight',
            'password'              => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'eight@example.com']);
    }

    public function test_a_static_array_still_works(): void
    {
        config()->set('kinetix.membership.assignable_roles', ['editor', 'viewer']);

        $this->actingAs($this->manager())
            ->getJson('/_kinetix/members')
            ->assertOk()
            ->assertJsonPath('assignable_roles', ['editor', 'viewer']);
    }

    private function manager(): DynamicRolesUser
    {
        $user = DynamicRolesUser::create(['name' => 'Manager', 'email' => 'manager@example.com']);
        $user->givePermissionTo('members.viewAny');
        $user->givePermissionTo('members.provision');

        return $user;
    }

    private function activationUrl(MemberProvision $provision): string
    {
        return URL::temporarySignedRoute(
            'kinetix.membership.activate.show',
            now()->addDay(),
            ['provision' => $provision->id],
        );
    }
}
