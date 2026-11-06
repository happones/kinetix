<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\CheckboxList;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\KinetixServiceProvider;
use Happones\Kinetix\Membership\Events\MemberRevoked;
use Happones\Kinetix\Membership\Events\MemberUpdated;
use Happones\Kinetix\Membership\KinetixMembership;
use Happones\Kinetix\Membership\MemberProvision;
use Happones\Kinetix\Membership\MemberProvisionStatus;
use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Tests\Concerns\CreatesMembershipTables;
use Happones\Kinetix\Tests\Fakes\BranchMemberFields;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;

class FieldsUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $guard_name = 'web';
}

class RelationUser extends FieldsUser
{
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(RelationBranch::class, 'branch_user', 'user_id', 'branch_id');
    }
}

class RelationBranch extends Model
{
    protected $table = 'branches';

    public $timestamps = false;
}

class MembershipFieldsTest extends TestCase
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
        $app['config']->set('kinetix.membership.user_model', FieldsUser::class);
        $app['config']->set('kinetix.membership.assignable_roles', ['editor', 'viewer']);
        $app['config']->set('kinetix.membership.fields', BranchMemberFields::class);
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

        DB::table('branches')->insert([
            ['id' => 1, 'team_id' => null, 'name' => 'North'],
            ['id' => 2, 'team_id' => null, 'name' => 'South'],
            ['id' => 3, 'team_id' => 2, 'name' => 'Foreign'],
        ]);

        foreach (KinetixPermissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (['editor', 'viewer'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    /**
     * @param array<int, string> $abilities
     */
    private function manager(array $abilities = ['members.provision', 'members.viewAny', 'members.update', 'members.revoke']): FieldsUser
    {
        $user = FieldsUser::create(['name' => 'Manager', 'email' => 'manager@example.com']);

        foreach ($abilities as $ability) {
            $user->givePermissionTo($ability);
        }

        return $user;
    }

    /**
     * @param  array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function invite(array $fields = []): array
    {
        return [
            'email'  => 'new@example.com',
            'role'   => 'editor',
            'fields' => $fields + ['portal_access' => true, 'job_title' => 'Cashier', 'branch_ids' => ['1']],
        ];
    }

    private function activate(MemberProvision $provision): TestResponse
    {
        $url = URL::temporarySignedRoute('kinetix.membership.activate', now()->addDay(), ['provision' => $provision->getKey()]);

        return $this->post($url, ['name' => 'New', 'password' => 'secret-password', 'password_confirmation' => 'secret-password']);
    }

    // -- default ---------------------------------------------------------------

    public function test_without_fields_the_index_only_gains_two_keys_and_input_is_ignored(): void
    {
        config()->set('kinetix.membership.fields', null);
        Schema::table('kinetix_member_provisions', fn (Blueprint $table) => $table->dropColumn('fields'));

        $manager = $this->manager();

        $this->actingAs($manager)->getJson('/_kinetix/members')
            ->assertOk()
            ->assertJsonPath('has_fields', false)
            ->assertJsonPath('fields_form', null);

        // The column is absent: nothing may read or write it.
        $this->actingAs($manager)
            ->postJson('/_kinetix/members', $this->invite())
            ->assertCreated();

        $provision = MemberProvision::firstOrFail();

        $this->actingAs($manager)
            ->putJson('/_kinetix/members/'.$provision->getKey(), ['role' => 'viewer', 'fields' => ['job_title' => 'x']])
            ->assertOk();

        $this->actingAs($manager)->getJson('/_kinetix/members/'.$provision->getKey().'/fields')->assertNotFound();
        $this->assertSame([], BranchMemberFields::$applied);
    }

    public function test_a_bad_class_fails_loudly(): void
    {
        config()->set('kinetix.membership.fields', \stdClass::class);

        $this->expectException(InvalidArgumentException::class);

        KinetixMembership::fields();
    }

    // -- index -----------------------------------------------------------------

    public function test_the_index_carries_the_provisioning_form_for_provisioners_only(): void
    {
        $response = $this->actingAs($this->manager())->getJson('/_kinetix/members')->assertOk();

        $this->assertTrue($response->json('has_fields'));
        $this->assertSame('create', $response->json('fields_form.operation'));
        $this->assertSame(['portal_access', 'job_title', 'internal_note', 'branch_ids'], array_column($response->json('fields_form.schema'), 'name'));
        // Only the team's branches are offered, and no rule object leaks out.
        $this->assertSame([1, 2], array_map('intval', array_keys((array) $response->json('fields_form.schema.3.options'))));
        $this->assertSame(['required'], $response->json('fields_form.rules.job_title'));

        $viewer = FieldsUser::create(['name' => 'V', 'email' => 'v@example.com']);
        $viewer->givePermissionTo('members.viewAny');

        $this->actingAs($viewer)->getJson('/_kinetix/members')
            ->assertOk()
            ->assertJsonPath('has_fields', true)
            ->assertJsonPath('fields_form', null);
    }

    // -- store -----------------------------------------------------------------

    public function test_store_returns_one_error_bag_and_rejects_foreign_options(): void
    {
        $this->actingAs($this->manager())
            ->postJson('/_kinetix/members', ['role' => 'editor', 'fields' => ['branch_ids' => ['3']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'fields.job_title', 'fields.branch_ids']);

        $this->assertSame(0, MemberProvision::count());
    }

    public function test_activation_mode_stores_the_json_and_applies_it_on_activation(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)
            ->postJson('/_kinetix/members', $this->invite(['smuggled' => 'x']))
            ->assertCreated();

        $provision = MemberProvision::firstOrFail();

        $this->assertSame(['portal_access' => true, 'job_title' => 'Cashier', 'internal_note' => null, 'branch_ids' => ['1']], $provision->fields);
        $this->assertSame([], BranchMemberFields::$applied);

        $this->activate($provision)->assertRedirect();

        $this->assertCount(1, BranchMemberFields::$applied);
        $this->assertSame('activation', BranchMemberFields::$applied[0][1]);

        $user = FieldsUser::where('email', 'new@example.com')->firstOrFail();

        $this->assertTrue((bool) $user->portal_access);
        $this->assertSame([1], DB::table('branch_user')->where('user_id', $user->getKey())->pluck('branch_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertNull($provision->fresh()->fields);
        $this->assertSame(MemberProvisionStatus::Active, $provision->fresh()->status);
    }

    public function test_activation_drops_an_option_that_disappeared(): void
    {
        $this->actingAs($this->manager())->postJson('/_kinetix/members', $this->invite(['branch_ids' => ['1', '2']]))->assertCreated();
        DB::table('branches')->where('id', 2)->delete();

        $this->activate(MemberProvision::firstOrFail())->assertRedirect();

        $user = FieldsUser::where('email', 'new@example.com')->firstOrFail();
        $this->assertSame([1], DB::table('branch_user')->where('user_id', $user->getKey())->pluck('branch_id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_a_failing_apply_rolls_activation_back(): void
    {
        $this->actingAs($this->manager())->postJson('/_kinetix/members', $this->invite())->assertCreated();
        $provision = MemberProvision::firstOrFail();

        BranchMemberFields::$fail = true;
        auth()->logout();

        $this->activate($provision)->assertStatus(500);

        $this->assertSame(0, FieldsUser::where('email', 'new@example.com')->count());
        $this->assertSame(MemberProvisionStatus::Pending, $provision->fresh()->status);
        $this->assertNotNull($provision->fresh()->fields);
        $this->assertGuest();
    }

    public function test_direct_mode_applies_at_store_and_leaves_no_json(): void
    {
        config()->set('kinetix.membership.provisioning', 'direct');
        config()->set('kinetix.membership.delivery', 'manual');

        $this->actingAs($this->manager())->postJson('/_kinetix/members', $this->invite())->assertCreated();

        $this->assertCount(1, BranchMemberFields::$applied);
        $this->assertNull(MemberProvision::firstOrFail()->fields);
        $this->assertSame(1, FieldsUser::where('email', 'new@example.com')->count());
    }

    public function test_a_failing_apply_in_direct_mode_persists_nothing(): void
    {
        config()->set('kinetix.membership.provisioning', 'direct');
        config()->set('kinetix.membership.delivery', 'manual');
        BranchMemberFields::$fail = true;

        $this->actingAs($this->manager())->postJson('/_kinetix/members', $this->invite())->assertStatus(500);

        $this->assertSame(0, FieldsUser::where('email', 'new@example.com')->count());
        $this->assertSame(0, MemberProvision::count());
    }

    public function test_reprovisioning_replaces_the_fields_and_resend_keeps_them(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager)->postJson('/_kinetix/members', $this->invite())->assertCreated();
        $provision = MemberProvision::firstOrFail();

        $this->actingAs($manager)->postJson('/_kinetix/members/'.$provision->getKey().'/resend')->assertOk();
        $this->assertSame('Cashier', $provision->fresh()->fields['job_title']);

        $this->actingAs($manager)->postJson('/_kinetix/members', $this->invite(['job_title' => 'Manager']))->assertCreated();
        $this->assertSame(1, MemberProvision::count());
        $this->assertSame('Manager', $provision->fresh()->fields['job_title']);
    }

    // -- fields GET ------------------------------------------------------------

    public function test_the_fields_endpoint_reads_pending_json_and_active_host_values(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager)->postJson('/_kinetix/members', $this->invite())->assertCreated();
        $provision = MemberProvision::firstOrFail();

        $this->actingAs($manager)->getJson("/_kinetix/members/{$provision->getKey()}/fields")
            ->assertOk()
            ->assertJsonPath('form.operation', 'edit')
            ->assertJsonPath('form.data.job_title', 'Cashier');

        $this->activate($provision)->assertRedirect();
        auth()->logout();
        FieldsUser::where('email', 'new@example.com')->update(['job_title' => 'Host title']);

        $this->actingAs($manager)->getJson("/_kinetix/members/{$provision->getKey()}/fields")
            ->assertOk()
            ->assertJsonPath('form.data.job_title', 'Host title')
            ->assertJsonPath('form.data.branch_ids', ['1']);
    }

    public function test_the_fields_endpoint_guards_ability_revoked_and_localizes(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager)->postJson('/_kinetix/members', $this->invite())->assertCreated();
        $provision = MemberProvision::firstOrFail();

        $limited = $this->manager(['members.viewAny']);
        $limited->update(['email' => 'limited@example.com']);
        $this->actingAs(FieldsUser::create(['name' => 'L', 'email' => 'l@example.com']))
            ->getJson("/_kinetix/members/{$provision->getKey()}/fields")->assertForbidden();

        $this->actingAs($manager)->deleteJson('/_kinetix/members/'.$provision->getKey())->assertOk();

        app()->setLocale('es');

        $this->actingAs($manager)->getJson("/_kinetix/members/{$provision->getKey()}/fields")
            ->assertStatus(422)
            ->assertJsonPath('message', __('kinetix.members_revoked'));

        $this->assertNotSame('This member was revoked; provision them again instead.', __('kinetix.members_revoked'));
    }

    // -- update ----------------------------------------------------------------

    public function test_updating_a_pending_member_writes_the_json_only(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager)->postJson('/_kinetix/members', $this->invite())->assertCreated();
        $provision = MemberProvision::firstOrFail();

        $this->actingAs($manager)
            ->putJson('/_kinetix/members/'.$provision->getKey(), ['role' => 'editor', 'fields' => ['job_title' => 'Boss', 'branch_ids' => ['2']]])
            ->assertOk();

        $this->assertSame('Boss', $provision->fresh()->fields['job_title']);
        $this->assertSame([], BranchMemberFields::$applied);
    }

    public function test_updating_an_active_member_applies_the_full_state(): void
    {
        $manager = $this->manager();
        config()->set('kinetix.membership.provisioning', 'direct');
        $this->actingAs($manager)->postJson('/_kinetix/members', $this->invite())->assertCreated();
        $provision                   = MemberProvision::firstOrFail();
        BranchMemberFields::$applied = [];

        $this->actingAs($manager)
            ->putJson('/_kinetix/members/'.$provision->getKey(), ['role' => 'editor', 'fields' => ['portal_access' => false, 'job_title' => 'Boss', 'branch_ids' => ['2']]])
            ->assertOk();

        $this->assertCount(1, BranchMemberFields::$applied);
        $this->assertSame('edit', BranchMemberFields::$applied[0][1]);
        $this->assertSame(['portal_access' => false, 'job_title' => 'Boss', 'internal_note' => null, 'branch_ids' => ['2']], BranchMemberFields::$applied[0][0]);
    }

    public function test_update_without_fields_leaves_them_alone_and_validates_options(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager)->postJson('/_kinetix/members', $this->invite())->assertCreated();
        $provision = MemberProvision::firstOrFail();

        $this->actingAs($manager)->putJson('/_kinetix/members/'.$provision->getKey(), ['role' => 'viewer'])->assertOk();
        $this->assertSame('Cashier', $provision->fresh()->fields['job_title']);
        $this->assertSame('viewer', $provision->fresh()->role);

        $this->actingAs($manager)
            ->putJson('/_kinetix/members/'.$provision->getKey(), ['role' => 'viewer', 'fields' => ['job_title' => 'x', 'branch_ids' => ['3']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fields.branch_ids']);
    }

    public function test_an_unchanged_locked_role_still_saves_fields_but_a_changed_one_does_not(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager)->postJson('/_kinetix/members', $this->invite())->assertCreated();
        $provision = MemberProvision::firstOrFail();

        config()->set('kinetix.membership.assignable_roles', ['viewer']);

        $this->actingAs($manager)
            ->putJson('/_kinetix/members/'.$provision->getKey(), ['role' => 'editor', 'fields' => ['job_title' => 'Boss']])
            ->assertOk();

        $this->actingAs($manager)
            ->putJson('/_kinetix/members/'.$provision->getKey(), ['role' => 'admin'])
            ->assertStatus(422);
    }

    public function test_a_disabled_field_cannot_be_changed_by_a_smuggled_value(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager)->postJson('/_kinetix/members', $this->invite())->assertCreated();
        $provision = MemberProvision::firstOrFail();

        BranchMemberFields::$lockTitle = true;

        $this->actingAs($manager)
            ->putJson('/_kinetix/members/'.$provision->getKey(), ['role' => 'editor', 'fields' => ['job_title' => 'Hacked']])
            ->assertOk();

        $this->assertSame('Cashier', $provision->fresh()->fields['job_title']);
    }

    public function test_a_revoked_member_cannot_be_updated(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager)->postJson('/_kinetix/members', $this->invite())->assertCreated();
        $provision = MemberProvision::firstOrFail();
        $this->actingAs($manager)->deleteJson('/_kinetix/members/'.$provision->getKey())->assertOk();

        $this->actingAs($manager)
            ->putJson('/_kinetix/members/'.$provision->getKey(), ['role' => 'editor', 'fields' => ['job_title' => 'x']])
            ->assertStatus(422);
    }

    public function test_another_teams_provision_is_not_found(): void
    {
        $manager = $this->manager();
        $other   = MemberProvision::create(['team_id' => 99, 'email' => 'o@example.com', 'role' => 'editor', 'status' => MemberProvisionStatus::Pending]);

        $this->actingAs($manager)->getJson("/_kinetix/members/{$other->getKey()}/fields")->assertNotFound();
    }

    // -- hidden and omitted fields ------------------------------------------------

    public function test_a_field_hidden_from_the_actor_keeps_its_value_when_a_pending_member_is_edited(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager)->postJson('/_kinetix/members', $this->invite(['internal_note' => 'secret']))->assertCreated();
        $provision = MemberProvision::firstOrFail();
        $provision->update(['fields' => [...$provision->fields, 'legacy' => 'kept']]);

        BranchMemberFields::$hideNote = true;

        $this->actingAs($manager)
            ->putJson('/_kinetix/members/'.$provision->getKey(), ['role' => 'editor', 'fields' => ['job_title' => 'Boss', 'internal_note' => 'overwritten']])
            ->assertOk();

        $stored = $provision->fresh()->fields;
        $this->assertSame('Boss', $stored['job_title']);
        $this->assertSame('secret', $stored['internal_note']);
        $this->assertSame('kept', $stored['legacy']);
    }

    public function test_a_field_hidden_from_the_actor_keeps_its_value_on_an_active_member_and_fires_no_event(): void
    {
        config()->set('kinetix.membership.provisioning', 'direct');
        $manager = $this->manager();
        $this->actingAs($manager)->postJson('/_kinetix/members', $this->invite(['internal_note' => 'secret']))->assertCreated();
        $provision                   = MemberProvision::firstOrFail();
        BranchMemberFields::$applied = [];
        $fired                       = [];
        Event::listen(MemberUpdated::class, function ($e) use (&$fired): void {
            $fired[] = $e;
        });

        BranchMemberFields::$hideNote = true;

        // Nothing visible changes: apply still sees the full state, no event.
        $this->actingAs($manager)
            ->putJson('/_kinetix/members/'.$provision->getKey(), ['role' => 'editor', 'fields' => ['portal_access' => true, 'job_title' => 'Cashier', 'branch_ids' => ['1']]])
            ->assertOk();

        $this->assertSame('secret', BranchMemberFields::$applied[0][0]['internal_note']);
        $this->assertSame('secret', FieldsUser::where('email', 'new@example.com')->value('internal_note'));
        $this->assertSame([], $fired);
    }

    public function test_the_edit_form_never_carries_hidden_or_unknown_values(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager)->postJson('/_kinetix/members', $this->invite(['internal_note' => 'secret']))->assertCreated();
        $provision = MemberProvision::firstOrFail();
        $provision->update(['fields' => [...$provision->fields, 'legacy' => 'x']]);

        BranchMemberFields::$hideNote = true;

        $data = $this->actingAs($manager)->getJson("/_kinetix/members/{$provision->getKey()}/fields")->assertOk()->json('form.data');

        $this->assertArrayNotHasKey('internal_note', $data);
        $this->assertArrayNotHasKey('legacy', $data);
        $this->assertSame('Cashier', $data['job_title']);

        // An active member: the extra key comes from the host's values().
        $provision->update(['status' => MemberProvisionStatus::Active, 'user_id' => FieldsUser::create(['name' => 'N', 'email' => 'n@example.com'])->getKey()]);
        BranchMemberFields::$legacy = 'from host';

        $data = $this->actingAs($manager)->getJson("/_kinetix/members/{$provision->getKey()}/fields")->assertOk()->json('form.data');
        $this->assertArrayNotHasKey('legacy', $data);
        $this->assertArrayNotHasKey('internal_note', $data);
    }

    public function test_a_disabled_field_keeps_its_value_on_an_active_member(): void
    {
        config()->set('kinetix.membership.provisioning', 'direct');
        $manager = $this->manager();
        $this->actingAs($manager)->postJson('/_kinetix/members', $this->invite())->assertCreated();
        $provision                     = MemberProvision::firstOrFail();
        BranchMemberFields::$applied   = [];
        BranchMemberFields::$lockTitle = true;

        $this->actingAs($manager)
            ->putJson('/_kinetix/members/'.$provision->getKey(), ['role' => 'editor', 'fields' => ['job_title' => 'Hacked', 'portal_access' => false, 'branch_ids' => ['2']]])
            ->assertOk();

        $this->assertSame('Cashier', BranchMemberFields::$applied[0][0]['job_title']);
        $this->assertSame('Cashier', FieldsUser::where('email', 'new@example.com')->value('job_title'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hiddenLayouts(): array
    {
        return ['section' => ['section'], 'grid' => ['grid'], 'fieldset' => ['fieldset']];
    }

    /**
     * @dataProvider hiddenLayouts
     */
    #[DataProvider('hiddenLayouts')]
    public function test_fields_inside_a_hidden_layout_are_protected(string $layout): void
    {
        $manager = $this->manager();
        $this->actingAs($manager)->postJson('/_kinetix/members', $this->invite())->assertCreated();
        $provision = MemberProvision::firstOrFail();
        $provision->update(['fields' => [...$provision->fields, 'restricted' => 'previous']]);

        BranchMemberFields::$layout = $layout;

        $data = $this->actingAs($manager)->getJson("/_kinetix/members/{$provision->getKey()}/fields")->assertOk()->json();
        $this->assertArrayNotHasKey('restricted', $data['form']['data']);
        $this->assertArrayNotHasKey('restricted', $data['form']['rules']);

        // A required field in the hidden layout does not 422, and a smuggled value is ignored.
        $this->actingAs($manager)
            ->putJson('/_kinetix/members/'.$provision->getKey(), ['role' => 'editor', 'fields' => ['job_title' => 'Boss', 'restricted' => 'hacked']])
            ->assertOk();

        $stored = $provision->fresh()->fields;
        $this->assertSame('previous', $stored['restricted']);
        $this->assertSame('Boss', $stored['job_title']);

        // The provisioning form leaves it out and does not require it either.
        $index = $this->actingAs($manager)->getJson('/_kinetix/members')->assertOk()->json('fields_form');
        $this->assertArrayNotHasKey('restricted', $index['data']);
        $this->actingAs($manager)->postJson('/_kinetix/members', ['email' => 'other@example.com', 'role' => 'editor', 'fields' => ['job_title' => 'x', 'restricted' => 'hacked']])->assertCreated();
        $this->assertArrayNotHasKey('restricted', MemberProvision::where('email', 'other@example.com')->firstOrFail()->fields);
    }

    public function test_a_hidden_default_is_not_sent_in_the_edit_form(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager)->postJson('/_kinetix/members', $this->invite(['internal_note' => 'secret']))->assertCreated();
        $provision                    = MemberProvision::firstOrFail();
        BranchMemberFields::$hideNote = true;

        $data = $this->actingAs($manager)->getJson("/_kinetix/members/{$provision->getKey()}/fields")->assertOk()->json('form.data');

        $this->assertArrayNotHasKey('internal_note', $data);
    }

    public function test_a_relationship_select_validates_by_query_and_ignores_the_options_cap(): void
    {
        config()->set('kinetix.forms.relationship_options_limit', 1);

        $form = Form::make()
            ->schema([CheckboxList::make('branches')->relationship('branches', 'name')])
            ->model(RelationUser::class)
            ->strictOptions();

        $form->validate(['branches' => ['1', '2']]);

        $this->expectException(ValidationException::class);
        $form->validate(['branches' => ['1', '3', '999']]);
    }

    // -- the rest of the guards ------------------------------------------------

    public function test_a_failing_direct_reprovision_leaves_an_existing_row_unchanged(): void
    {
        config()->set('kinetix.membership.provisioning', 'direct');
        config()->set('kinetix.membership.delivery', 'manual');
        $existing = MemberProvision::create([
            'email'  => 'new@example.com', 'role' => 'viewer', 'name' => 'Old',
            'status' => MemberProvisionStatus::Revoked, 'fields' => ['job_title' => 'Old title'],
        ]);
        BranchMemberFields::$fail = true;

        $this->actingAs($this->manager())->postJson('/_kinetix/members', $this->invite())->assertStatus(500);

        $fresh = $existing->fresh();
        $this->assertSame(MemberProvisionStatus::Revoked, $fresh->status);
        $this->assertSame('viewer', $fresh->role);
        $this->assertSame(['job_title' => 'Old title'], $fresh->fields);
        $this->assertSame(1, MemberProvision::count());
        $this->assertSame(0, FieldsUser::where('email', 'new@example.com')->count());
    }

    public function test_apply_runs_with_the_provisions_team_pinned(): void
    {
        config()->set('permission.teams', true);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $migration = require __DIR__.'/../../database/migrations/2026_01_01_000017_add_kinetix_team_fields_to_permission_tables.php';
        $migration->up();

        $provision = MemberProvision::create([
            'team_id' => 7, 'email' => 'team@example.com', 'role' => 'editor',
            'status'  => MemberProvisionStatus::Pending, 'expires_at' => now()->addDay(),
            'fields'  => ['job_title' => 'Cashier'],
        ]);

        $this->activate($provision)->assertRedirect();

        $this->assertSame(7, (int) BranchMemberFields::$teamSeen);
        $this->assertNull(app(PermissionRegistrar::class)->getPermissionsTeamId());
    }

    public function test_an_outer_transaction_that_rolls_back_dispatches_no_revoked_event(): void
    {
        $provision = MemberProvision::create(['email' => 'p@example.com', 'role' => 'editor', 'status' => MemberProvisionStatus::Pending]);
        $fired     = [];
        Event::listen(MemberRevoked::class, function ($e) use (&$fired): void {
            $fired[] = $e;
        });

        try {
            DB::transaction(function () use ($provision): void {
                KinetixMembership::revoke($provision);

                throw new \RuntimeException('outer failure');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame([], $fired);
        $this->assertSame(MemberProvisionStatus::Pending, $provision->fresh()->status);

        KinetixMembership::revoke($provision->fresh());
        $this->assertCount(1, $fired);
    }

    public function test_a_second_revoke_or_activation_of_a_stale_copy_does_nothing(): void
    {
        $provision = MemberProvision::create(['email' => 'p@example.com', 'role' => 'editor', 'status' => MemberProvisionStatus::Pending, 'expires_at' => now()->addDay()]);
        $stale     = MemberProvision::find($provision->getKey());
        $fired     = [];
        Event::listen(MemberRevoked::class, function ($e) use (&$fired): void {
            $fired[] = $e;
        });

        KinetixMembership::revoke($provision);
        KinetixMembership::revoke($stale);

        $this->assertCount(1, $fired);

        // The stale link was minted while pending: the locked re-read refuses it.
        $url = URL::temporarySignedRoute('kinetix.membership.activate', now()->addDay(), ['provision' => $provision->getKey()]);
        $this->post($url, ['name' => 'X', 'password' => 'secret-password', 'password_confirmation' => 'secret-password'])->assertStatus(410);
    }

    // -- migration -------------------------------------------------------------

    public function test_the_migration_is_idempotent_and_published_under_the_membership_tag(): void
    {
        Schema::table('kinetix_member_provisions', fn (Blueprint $table) => $table->dropColumn('fields'));

        $migration = require __DIR__.'/../../database/migrations/2026_01_01_000053_add_fields_to_kinetix_member_provisions_table.php';
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('kinetix_member_provisions', 'fields'));

        $migration->down();
        $this->assertFalse(Schema::hasColumn('kinetix_member_provisions', 'fields'));

        $paths = array_keys(ServiceProvider::pathsToPublish(KinetixServiceProvider::class, 'kinetix-membership-migrations'));
        $this->assertNotEmpty(array_filter($paths, static fn (string $path): bool => str_ends_with($path, '000053_add_fields_to_kinetix_member_provisions_table.php')));
    }
}
