<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Billing\Concerns\EnforcesPlanLimits;
use Happones\Kinetix\Billing\Concerns\HasPlan;
use Happones\Kinetix\Billing\Exceptions\PlanLimitExceededException;
use Happones\Kinetix\Billing\Plan;
use Happones\Kinetix\Billing\PlanCatalog;
use Happones\Kinetix\Entitlements\DenialReason;
use Happones\Kinetix\Entitlements\EntitlementRegistry;
use Happones\Kinetix\Entitlements\KinetixEntitlements;
use Happones\Kinetix\Features\KinetixFeatures;
use Happones\Kinetix\Impersonation\ImpersonationManager;
use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Permissions\SuperAdmin;
use Happones\Kinetix\Permissions\TeamOwner;
use Happones\Kinetix\Support\Memo;
use Happones\Kinetix\Tests\Concerns\CreatesPermissionTables;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;

class BypassUser extends Authenticatable
{
    use HasPlan;
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $guard_name = 'web';

    public function subscription(string $type = 'default'): mixed
    {
        return null;
    }

    public function onGenericTrial(): bool
    {
        return false;
    }

    /** Every user in this suite owns "their" team, for the owner bypass. */
    public function ownsTeam(mixed $team = null): bool
    {
        return (bool) ($this->is_owner ?? false);
    }

    /**
     * The active team the owner bypass resolves against. Null for a user with
     * no team context — which is itself a tested case: the bypass grants
     * nothing when it cannot tell WHICH team is being acted on.
     */
    public function getCurrentTeamAttribute(): ?BypassTeam
    {
        return ($this->has_team ?? false) ? BypassTeam::first() : null;
    }
}

class BypassTeam extends Model
{
    protected $table = 'bypass_teams';

    public $timestamps = false;

    protected $guarded = [];
}

class BypassPost extends Model
{
    protected $table = 'bypass_posts';

    public $timestamps = false;

    protected $guarded = [];
}

class BypassPostPolicy
{
    public function update(BypassUser $user, BypassPost $post): bool
    {
        // The tenancy boundary: this policy NEVER lets one tenant touch
        // another's record, whatever roles the user holds.
        return (int) $post->owner_id === (int) $user->getKey();
    }
}

class BypassProject extends Model
{
    use EnforcesPlanLimits;

    protected $table = 'bypass_projects';

    public $timestamps = false;

    protected $guarded = [];

    public function planLimitKey(): string
    {
        return 'bypass_projects';
    }
}

/**
 * The precedence matrix between Kinetix's two Gate bypasses (super-admin, team
 * owner) and the four gating layers.
 *
 * Every cell here is a deliberate design decision that used to live only in the
 * code, and the two that surprise people are opposites: a super-admin
 * short-circuits **model policies** (a blanket `Gate::before`) but does NOT get
 * a plan they haven't paid for, while a team owner does NOT short-circuit
 * policies (their bypass is scoped to registry keys, so it can't cross a
 * tenancy boundary).
 */
class BypassPrecedenceTest extends TestCase
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
        $app['config']->set('kinetix.permissions.enabled', true);
        $app['config']->set('kinetix.permissions.owner_bypass', true);
        $app['config']->set('kinetix.billing.enabled', true);
        $app['config']->set('kinetix.billing.billable', BypassUser::class);
        $app['config']->set('kinetix.features.enabled', true);
        $app['config']->set('kinetix.features.driver', 'native');
        $app['config']->set('kinetix.entitlements.enabled', true);
        $app['config']->set('auth.providers.users.model', BypassUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPermissionTables();

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_owner')->default(false);
            $table->boolean('has_team')->default(true);
        });

        Schema::create('bypass_teams', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        BypassTeam::create(['name' => 'Acme']);

        Schema::create('bypass_posts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('owner_id');
        });

        Schema::create('bypass_projects', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('bypass_user_id')->nullable();
            $table->string('name');
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->decimal('monthly_price', 8, 2)->default(0);
            $table->decimal('yearly_price', 8, 2)->nullable();
            $table->string('stripe_monthly_price_id')->nullable();
            $table->string('stripe_yearly_price_id')->nullable();
            $table->json('features')->nullable();
            $table->json('highlighted_features')->nullable();
            $table->unsignedInteger('trial_days')->nullable();
            $table->boolean('is_free')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        KinetixPermissions::feature('posts')->crud();

        foreach (KinetixPermissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Gate::policy(BypassPost::class, BypassPostPolicy::class);
    }

    protected function tearDown(): void
    {
        app(EntitlementRegistry::class)->reset();
        PlanCatalog::flush();
        Memo::flush();
        SuperAdmin::flush();
        TeamOwner::flush();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $features
     */
    private function freePlan(array $features = []): Plan
    {
        return Plan::create(['name' => 'Free', 'is_free' => true, 'features' => $features]);
    }

    private function superAdmin(): BypassUser
    {
        Role::findOrCreate('super-admin', 'web');
        $user = BypassUser::create(['name' => 'Root']);
        $user->assignRole('super-admin');

        return $user;
    }

    private function owner(): BypassUser
    {
        return BypassUser::create(['name' => 'Owner', 'is_owner' => true, 'has_team' => true]);
    }

    private function ownerWithoutTeamContext(): BypassUser
    {
        return BypassUser::create(['name' => 'Owner', 'is_owner' => true, 'has_team' => false]);
    }

    private function plain(): BypassUser
    {
        return BypassUser::create(['name' => 'Jane']);
    }

    // -- Super-admin ---------------------------------------------------------

    public function test_a_super_admin_passes_every_registered_ability(): void
    {
        $root = $this->superAdmin();

        $this->assertTrue($root->getAllPermissions()->isEmpty());
        $this->assertTrue(Gate::forUser($root)->allows('posts.update'));
        $this->assertTrue(Gate::forUser($root)->allows('roles.manage'));
    }

    public function test_a_super_admin_short_circuits_model_policies(): void
    {
        $root      = $this->superAdmin();
        $otherPost = BypassPost::create(['owner_id' => $this->plain()->getKey()]);

        // The super-admin bypass is a BLANKET `Gate::before` — unlike the owner
        // bypass, it does short-circuit policies, including the tenancy
        // boundary. That is the point of a platform super-admin, and the reason
        // the role must be handed out sparingly.
        $this->assertTrue(Gate::forUser($root)->allows('update', $otherPost));
    }

    public function test_a_super_admin_does_not_get_a_plan_they_have_not_paid_for(): void
    {
        $this->freePlan(['capabilities' => ['api' => false]]);
        $root = $this->superAdmin();

        // Billing is a commercial boundary, not an authorization one: the plan
        // helpers never consult the Gate, so no role can grant a capability.
        $this->assertFalse($root->planAllows('api'));
        $this->assertFalse($root->canUseFeature('capabilities.api'));
    }

    public function test_a_super_admin_is_still_blocked_by_a_plan_usage_limit(): void
    {
        $this->freePlan(['usage' => ['bypass_projects' => 1]]);
        $root = $this->superAdmin();
        $this->actingAs($root);

        BypassProject::create(['bypass_user_id' => $root->getKey(), 'name' => 'One']);

        $this->expectException(PlanLimitExceededException::class);
        BypassProject::create(['bypass_user_id' => $root->getKey(), 'name' => 'Two']);
    }

    public function test_a_super_admin_does_not_turn_on_a_feature_flag(): void
    {
        KinetixFeatures::define('beta', false);
        $this->actingAs($this->superAdmin());

        // A flag is a rollout switch, not a permission — nobody is exempt.
        $this->assertFalse(KinetixFeatures::active('beta'));
    }

    public function test_a_super_admin_passes_an_entitlements_permission_layer_only(): void
    {
        $this->freePlan(['capabilities' => ['api' => false]]);
        KinetixFeatures::define('beta', false);

        KinetixEntitlements::define('perm.only')->permission('posts.update');
        KinetixEntitlements::define('plan.gated')->plan('api');
        KinetixEntitlements::define('flag.gated')->flag('beta');

        $this->actingAs($this->superAdmin());

        // The permission layer goes through the Gate, so the bypass applies…
        $this->assertTrue(KinetixEntitlements::allows('perm.only'));
        // …and the other layers never consult it.
        $this->assertSame(DenialReason::Plan, KinetixEntitlements::check('plan.gated')->reason);
        $this->assertSame(DenialReason::Flag, KinetixEntitlements::check('flag.gated')->reason);
    }

    public function test_a_super_admin_passes_abilities_outside_the_kinetix_registry_too(): void
    {
        Gate::define('unregistered.thing', fn (): bool => false);

        // Unlike the owner bypass, this one is not scoped to registry keys —
        // it is a blanket `Gate::before`, so it covers the app's own abilities
        // as well.
        $this->assertTrue(Gate::forUser($this->superAdmin())->allows('unregistered.thing'));
    }

    // -- Impersonation -------------------------------------------------------

    public function test_impersonation_swaps_which_user_the_bypasses_answer_for(): void
    {
        config()->set('kinetix.impersonation.enabled', true);

        $root = $this->superAdmin();
        $jane = $this->plain();
        $post = BypassPost::create(['owner_id' => $root->getKey()]);

        $this->actingAs($root);
        $this->assertTrue(Gate::allows('posts.update'));

        // Impersonation replaces the authenticated user outright, so every
        // bypass is re-evaluated for the TARGET — an admin does not carry their
        // super-admin into the session they are inspecting.
        app(ImpersonationManager::class)->start($jane);
        SuperAdmin::flush();
        Memo::flush();

        $this->assertFalse(Gate::forUser(auth()->user())->allows('posts.update'));
        $this->assertFalse(Gate::forUser(auth()->user())->allows('update', $post));
    }

    // -- Team owner ----------------------------------------------------------

    public function test_an_owner_passes_registered_abilities_without_holding_any(): void
    {
        $owner = $this->owner();

        $this->assertTrue($owner->getAllPermissions()->isEmpty());
        $this->assertTrue(Gate::forUser($owner)->allows('posts.update'));
        $this->assertTrue(Gate::forUser($owner)->allows('roles.manage'));
    }

    public function test_an_owner_does_no_t_short_circuit_model_policies(): void
    {
        $owner     = $this->owner();
        $ownPost   = BypassPost::create(['owner_id' => $owner->getKey()]);
        $otherPost = BypassPost::create(['owner_id' => $this->plain()->getKey()]);

        // The critical difference from the super-admin: the owner bypass is
        // scoped to REGISTRY keys, so policies keep running and the bypass can
        // never cross the tenancy boundary into another tenant's records.
        $this->assertTrue(Gate::forUser($owner)->allows('update', $ownPost));
        $this->assertFalse(Gate::forUser($owner)->allows('update', $otherPost));
    }

    public function test_an_owner_does_not_get_a_plan_or_a_flag(): void
    {
        $this->freePlan(['capabilities' => ['api' => false]]);
        KinetixFeatures::define('beta', false);

        $owner = $this->owner();
        $this->actingAs($owner);

        $this->assertFalse($owner->planAllows('api'));
        $this->assertFalse(KinetixFeatures::active('beta'));
    }

    public function test_the_owner_bypass_grants_nothing_without_a_resolvable_team(): void
    {
        // Ownership is a question about a SPECIFIC team, so with no team in
        // context (no `{current_team}` segment and no `currentTeam`) the bypass
        // has nothing to answer about and grants nothing. It fails closed.
        $owner = $this->ownerWithoutTeamContext();

        $this->assertFalse(Gate::forUser($owner)->allows('posts.update'));
    }

    public function test_an_unregistered_ability_is_never_granted_by_the_owner_bypass(): void
    {
        Gate::define('unregistered.thing', fn (): bool => false);

        // The bypass only covers keys in the Kinetix registry; an app ability
        // outside it is untouched.
        $this->assertFalse(Gate::forUser($this->owner())->allows('unregistered.thing'));
    }

    // -- Neither -------------------------------------------------------------

    public function test_a_plain_user_gets_nothing_from_either_bypass(): void
    {
        $jane = $this->plain();
        $post = BypassPost::create(['owner_id' => $jane->getKey()]);

        $this->assertFalse(Gate::forUser($jane)->allows('posts.update'));
        // …but their own policy still passes on their own record.
        $this->assertTrue(Gate::forUser($jane)->allows('update', $post));
    }

    public function test_stored_permissions_still_win_for_everyone_else(): void
    {
        $role = Role::findOrCreate('editor', 'web');
        $role->syncPermissions(['posts.update']);

        $jane = $this->plain();
        $jane->assignRole('editor');

        $this->assertTrue(Gate::forUser($jane)->allows('posts.update'));
        $this->assertFalse(Gate::forUser($jane)->allows('posts.delete'));
    }
}
