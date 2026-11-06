<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Membership\Events\MemberActivated;
use Happones\Kinetix\Membership\Events\MemberProvisioned;
use Happones\Kinetix\Membership\Events\MemberRevoked;
use Happones\Kinetix\Membership\Events\MemberUpdated;
use Happones\Kinetix\Membership\KinetixMembership;
use Happones\Kinetix\Membership\MemberProvision;
use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Tests\Concerns\CreatesMembershipTables;
use Happones\Kinetix\Tests\Fakes\BranchMemberFields;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionServiceProvider;

class MembershipEventsTest extends TestCase
{
    use CreatesMembershipTables;

    /** @var array<int, object> */
    private array $events = [];

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
        $app['config']->set('kinetix.membership.user_model', FieldsUser::class);
        $app['config']->set('kinetix.membership.assignable_roles', ['editor', 'viewer']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        BranchMemberFields::reset();
        Notification::fake();
        $this->createMembershipTables();

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('portal_access')->default(false);
            $table->string('job_title')->nullable();
            $table->text('internal_note')->nullable();
        });
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('name');
        });
        Schema::create('branch_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('branch_id');
        });
        DB::table('branches')->insert([['id' => 1, 'team_id' => null, 'name' => 'North']]);

        foreach (KinetixPermissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (['editor', 'viewer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        // Real listeners, not Event::fake(): the after-commit behavior is the point.
        foreach ([MemberProvisioned::class, MemberActivated::class, MemberUpdated::class, MemberRevoked::class] as $event) {
            Event::listen($event, function (object $e): void {
                $this->events[] = $e;
            });
        }
    }

    private function manager(): FieldsUser
    {
        $user = FieldsUser::create(['name' => 'Manager', 'email' => 'manager@example.com']);

        foreach (['members.provision', 'members.viewAny', 'members.update', 'members.revoke'] as $ability) {
            $user->givePermissionTo($ability);
        }

        return $user;
    }

    /**
     * @return array<int, class-string>
     */
    private function fired(): array
    {
        return array_map(static fn (object $e): string => $e::class, $this->events);
    }

    public function test_all_four_events_dispatch_after_commit(): void
    {
        foreach ([MemberProvisioned::class, MemberActivated::class, MemberUpdated::class, MemberRevoked::class] as $event) {
            $this->assertContains(ShouldDispatchAfterCommit::class, class_implements($event));
        }
    }

    public function test_provisioning_and_activation_fire_in_order_with_the_documented_payload(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->postJson('/_kinetix/members', ['email' => 'new@example.com', 'role' => 'editor'])->assertCreated();

        $this->assertSame([MemberProvisioned::class], $this->fired());
        $this->assertSame($manager->getKey(), $this->events[0]->actor->getKey());
        $this->assertSame([], $this->events[0]->fields);

        auth()->logout();
        $provision = MemberProvision::firstOrFail();
        $url       = URL::temporarySignedRoute('kinetix.membership.activate', now()->addDay(), ['provision' => $provision->getKey()]);

        $this->post($url, ['name' => 'New', 'password' => 'secret-password', 'password_confirmation' => 'secret-password'])->assertRedirect();

        $this->assertSame([MemberProvisioned::class, MemberActivated::class], $this->fired());
        $this->assertFalse($this->events[1]->direct);
        $this->assertSame('new@example.com', $this->events[1]->user->email);
    }

    public function test_direct_provisioning_also_fires_activated_with_direct_true(): void
    {
        config()->set('kinetix.membership.provisioning', 'direct');
        config()->set('kinetix.membership.delivery', 'manual');

        $this->actingAs($this->manager())->postJson('/_kinetix/members', ['email' => 'new@example.com', 'role' => 'editor'])->assertCreated();

        $this->assertSame([MemberProvisioned::class, MemberActivated::class], $this->fired());
        $this->assertTrue($this->events[1]->direct);
    }

    public function test_updated_fires_only_on_a_real_change_and_its_halves_are_independent(): void
    {
        config()->set('kinetix.membership.fields', BranchMemberFields::class);

        $manager = $this->manager();
        $invite  = ['email' => 'new@example.com', 'role' => 'editor', 'fields' => ['job_title' => 'Cashier', 'branch_ids' => ['1']]];
        $this->actingAs($manager)->postJson('/_kinetix/members', $invite)->assertCreated();
        $id           = MemberProvision::firstOrFail()->getKey();
        $this->events = [];

        // Nothing changed: no event.
        $this->actingAs($manager)->putJson("/_kinetix/members/{$id}", ['role' => 'editor', 'fields' => $invite['fields'] + ['portal_access' => false]])->assertOk();
        $this->assertSame([], $this->events);

        // Fields only.
        $this->actingAs($manager)->putJson("/_kinetix/members/{$id}", ['role' => 'editor', 'fields' => ['job_title' => 'Boss', 'branch_ids' => ['1']]])->assertOk();
        $this->assertCount(1, $this->events);
        $this->assertFalse($this->events[0]->roleChanged());
        $this->assertTrue($this->events[0]->fieldsChanged());
        $this->assertSame('Cashier', $this->events[0]->previousFields['job_title']);
        $this->assertSame('Boss', $this->events[0]->fields['job_title']);

        // Role only.
        $this->actingAs($manager)->putJson("/_kinetix/members/{$id}", ['role' => 'viewer'])->assertOk();
        $this->assertCount(2, $this->events);
        $this->assertTrue($this->events[1]->roleChanged());
        $this->assertFalse($this->events[1]->fieldsChanged());
        $this->assertSame(['editor', 'viewer'], [$this->events[1]->previousRole, $this->events[1]->role]);
    }

    public function test_fields_changed_ignores_list_order_and_key_order(): void
    {
        $provision = new MemberProvision;

        $same = new MemberUpdated($provision, null, 'editor', 'editor', ['b' => ['2', '1'], 'a' => 1], ['a' => 1, 'b' => ['1', '2']]);
        $diff = new MemberUpdated($provision, null, 'editor', 'editor', ['a' => 1], ['a' => 2]);

        $this->assertFalse($same->fieldsChanged());
        $this->assertTrue($diff->fieldsChanged());
    }

    public function test_revoked_fires_once_with_the_actor_from_http_and_null_from_code(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager)->postJson('/_kinetix/members', ['email' => 'a@example.com', 'role' => 'editor'])->assertCreated();
        $this->actingAs($manager)->postJson('/_kinetix/members', ['email' => 'b@example.com', 'role' => 'editor'])->assertCreated();
        $this->events = [];

        [$first, $second] = MemberProvision::orderBy('id')->get()->all();

        $this->actingAs($manager)->deleteJson('/_kinetix/members/'.$first->getKey())->assertOk();
        $this->actingAs($manager)->deleteJson('/_kinetix/members/'.$first->getKey())->assertOk();
        KinetixMembership::revoke($second);

        $this->assertSame([MemberRevoked::class, MemberRevoked::class], $this->fired());
        $this->assertSame($manager->getKey(), $this->events[0]->actor->getKey());
        $this->assertNull($this->events[1]->actor);
    }

    public function test_a_rolled_back_activation_dispatches_nothing(): void
    {
        config()->set('kinetix.membership.fields', BranchMemberFields::class);

        $this->actingAs($this->manager())
            ->postJson('/_kinetix/members', ['email' => 'new@example.com', 'role' => 'editor', 'fields' => ['job_title' => 'Cashier']])
            ->assertCreated();
        auth()->logout();
        $this->events = [];

        BranchMemberFields::$fail = true;
        $provision                = MemberProvision::firstOrFail();
        $url                      = URL::temporarySignedRoute('kinetix.membership.activate', now()->addDay(), ['provision' => $provision->getKey()]);

        $this->post($url, ['name' => 'New', 'password' => 'secret-password', 'password_confirmation' => 'secret-password'])->assertStatus(500);

        $this->assertSame([], $this->events);
    }
}
