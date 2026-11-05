<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Billing\BillingManager;
use Happones\Kinetix\Billing\BillingRoutes;
use Happones\Kinetix\Billing\Plan;
use Happones\Kinetix\Permissions\KinetixRolesSeeder;
use Happones\Kinetix\Permissions\TeamOwner;
use Happones\Kinetix\Tests\Concerns\CreatesPermissionTables;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;
use Symfony\Component\HttpFoundation\Response;

class BaSubscription
{
    public string $stripe_status = 'active';

    public ?object $ends_at = null;

    public ?string $stripe_price = 'price_pro_m';

    public function __construct(public BaTeam $team) {}

    public function onTrial(): bool
    {
        return false;
    }

    public function active(): bool
    {
        return true;
    }

    public function onGracePeriod(): bool
    {
        return true;
    }

    public function incomplete(): bool
    {
        return false;
    }

    public function cancel(): void
    {
        BaTeam::$calls[] = 'cancel';
    }

    public function resume(): void
    {
        BaTeam::$calls[] = 'resume';
    }

    public function swap(string $price): void
    {
        BaTeam::$calls[] = "swap:{$price}";
    }
}

/**
 * A team billed through Cashier, faked: every write it receives is recorded
 * in {@see $calls}, so a refused request provably reached nothing.
 */
class BaTeam extends Model
{
    protected $table = 'ba_teams';

    public $timestamps = false;

    protected $guarded = [];

    public ?string $stripe_id = 'cus_team';

    /** @var array<int, string> */
    public static array $calls = [];

    public function subscribed(string $type = 'default'): bool
    {
        return true;
    }

    public function subscription(string $type = 'default'): BaSubscription
    {
        return new BaSubscription($this);
    }

    public function paymentMethods(): Collection
    {
        return collect();
    }

    public function defaultPaymentMethod(): ?object
    {
        return null;
    }

    public function hasDefaultPaymentMethod(): bool
    {
        return false;
    }

    public function addPaymentMethod(string $paymentMethod): void
    {
        static::$calls[] = "addPaymentMethod:{$paymentMethod}";
    }

    public function findPaymentMethod(string $id): object
    {
        return new class
        {
            public function delete(): void
            {
                BaTeam::$calls[] = 'deletePaymentMethod';
            }
        };
    }

    public function createSetupIntent(): object
    {
        static::$calls[] = 'createSetupIntent';

        return (object) ['client_secret' => 'seti_secret'];
    }

    public function downloadInvoice(string $id, array $data = []): Response
    {
        return response('invoice '.$id);
    }
}

class BaUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $guard_name = 'web';

    /**
     * @return BelongsTo<BaTeam, $this>
     */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(BaTeam::class, 'current_team_id');
    }

    /**
     * @return BelongsToMany<BaTeam, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(BaTeam::class, 'ba_team_user', 'user_id', 'team_id');
    }

    public function ownsTeam(Model $team): bool
    {
        return (int) $team->getAttribute('owner_id') === (int) $this->getKey();
    }
}

class BaBillingAccess
{
    public static function allows(?Model $user, Model $billable, string $ability): bool
    {
        return $ability === 'manage';
    }
}

/**
 * Who may read and who may change a team's billing. Reading (the page, the
 * invoices) takes `billing.view`, every write takes `billing.manage`, both
 * answered in the BILLED team's permission context.
 */
class BillingAuthorizationTest extends TestCase
{
    use CreatesPermissionTables;

    private BaTeam $team;

    private BaTeam $otherTeam;

    /**
     * @param  Application        $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), InertiaServiceProvider::class, PermissionServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.billing.middleware', ['web', 'auth']);
        $app['config']->set('kinetix.billing.enabled', true);
        $app['config']->set('kinetix.billing.view', 'Billing/Index');
        $app['config']->set('cashier.secret', 'sk_test_fake');
        $app['config']->set('kinetix.permissions.enabled', true);
        $app['config']->set('kinetix.permissions.teams', true);
        $app['config']->set('permission.teams', true);
    }

    /**
     * @param Application $app
     */
    protected function withTeamBilling($app): void
    {
        $app['config']->set('kinetix.billing.teams', true);
    }

    /**
     * @param Application $app
     */
    protected function withOwnerBypass($app): void
    {
        $app['config']->set('kinetix.permissions.owner_bypass.using', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPermissionTables();
        $migration = require __DIR__.'/../../database/migrations/2026_01_01_000017_add_kinetix_team_fields_to_permission_tables.php';
        $migration->up();

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('current_team_id')->nullable();
        });

        Schema::create('ba_team_user', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('team_id');
        });

        Schema::create('ba_teams', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('owner_id')->nullable();
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
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

        // Eloquent memoizes guardable columns per class for the whole process;
        // the teams migration added `team_id` after earlier classes looked.
        (new \ReflectionProperty(Model::class, 'guardableColumns'))->setValue(null, []);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        (new KinetixRolesSeeder)->run();

        Plan::create([
            'name'                    => 'Pro',
            'slug'                    => 'pro',
            'monthly_price'           => 29,
            'stripe_monthly_price_id' => 'price_pro_m',
        ]);

        Route::get('login', fn () => 'login')->name('login');
        BillingRoutes::register();

        BaTeam::$calls = [];
        TeamOwner::flush();

        $this->team      = BaTeam::create([]);
        $this->otherTeam = BaTeam::create([]);

        config()->set('kinetix.billing.resolve_billable', fn () => $this->team);
    }

    private function member(?string $role = null, ?BaTeam $inTeam = null): BaUser
    {
        $user = BaUser::create(['name' => 'Member', 'current_team_id' => $this->team->getKey()]);

        if ($role !== null) {
            $registrar = app(PermissionRegistrar::class);
            $registrar->setPermissionsTeamId(($inTeam ?? $this->team)->getKey());
            $user->assignRole($role);
            $registrar->setPermissionsTeamId(null);
            $user->unsetRelation('roles');
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function pageProps(BaUser $user): array
    {
        return $this->actingAs($user)
            ->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Version', '1')
            ->get(route('billing.index'))
            ->assertOk()
            ->json('props');
    }

    private function assertEveryWriteIsForbidden(BaUser $user): void
    {
        $this->actingAs($user);

        $this->post(route('billing.subscribe'), ['plan_slug' => 'pro'])->assertForbidden();
        $this->post(route('billing.payment-methods.add'), ['payment_method' => 'pm_1'])->assertForbidden();
        $this->delete(route('billing.payment-methods.remove', ['id' => 'pm_1']))->assertForbidden();
        $this->post(route('billing.cancel'))->assertForbidden();
        $this->post(route('billing.resume'))->assertForbidden();

        $this->assertSame([], BaTeam::$calls);
    }

    public function test_the_seeded_presets_carry_the_billing_abilities(): void
    {
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('billing.manage'));
        $this->assertTrue(Role::findByName('editor', 'web')->hasPermissionTo('billing.manage'));
        $this->assertTrue(Role::findByName('viewer', 'web')->hasPermissionTo('billing.view'));
        $this->assertFalse(Role::findByName('viewer', 'web')->hasPermissionTo('billing.manage'));
    }

    public function test_a_member_without_a_billing_role_can_neither_read_nor_change_it(): void
    {
        $member = $this->member();

        $this->actingAs($member)->get(route('billing.index'))->assertForbidden();
        $this->get(route('billing.invoices.download', ['id' => 'in_1']))->assertForbidden();

        $this->assertEveryWriteIsForbidden($member);
    }

    public function test_a_viewer_reads_the_page_and_invoices_but_changes_nothing(): void
    {
        $viewer = $this->member('viewer');

        $props = $this->pageProps($viewer);

        $this->assertSame(['view' => true, 'manage' => false], $props['can']);
        $this->assertNull($props['intent']);

        $this->get(route('billing.invoices.download', ['id' => 'in_1']))->assertOk();

        // No setup intent, so no Stripe customer was made for a reader.
        $this->assertEveryWriteIsForbidden($viewer);
    }

    public function test_a_billing_manager_changes_the_billing(): void
    {
        $admin = $this->member('admin');

        $props = $this->pageProps($admin);

        $this->assertSame(['view' => true, 'manage' => true], $props['can']);
        $this->assertSame('seti_secret', $props['intent']['client_secret']);

        $this->post(route('billing.cancel'))->assertRedirect();
        $this->post(route('billing.resume'))->assertRedirect();

        $this->assertSame(['createSetupIntent', 'cancel', 'resume'], BaTeam::$calls);
    }

    public function test_a_role_held_in_another_team_grants_nothing_here(): void
    {
        $user = $this->member('admin', $this->otherTeam);

        $this->actingAs($user)->get(route('billing.index'))->assertForbidden();
        $this->assertEveryWriteIsForbidden($user);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($this->otherTeam->getKey());

        $this->assertFalse(BillingManager::allows('manage', $user, $this->team));
        $this->assertTrue(BillingManager::allows('manage', $user, $this->otherTeam));

        // The caller's team context is left as it was.
        $this->assertSame($this->otherTeam->getKey(), $registrar->getPermissionsTeamId());
    }

    #[DefineEnvironment('withOwnerBypass')]
    public function test_the_owner_bypass_lets_the_owner_manage_without_a_role(): void
    {
        $owner = $this->member();
        $this->team->forceFill(['owner_id' => $owner->getKey()])->save();

        $this->assertSame(['view' => true, 'manage' => true], $this->pageProps($owner)['can']);

        $this->post(route('billing.cancel'))->assertRedirect();
        $this->assertContains('cancel', BaTeam::$calls);
    }

    public function test_without_permissions_members_view_and_the_owner_manages(): void
    {
        config()->set('kinetix.permissions.enabled', false);

        $member = $this->member();
        $owner  = $this->member();
        $this->team->forceFill(['owner_id' => $owner->getKey()])->save();

        $this->assertSame(['view' => true, 'manage' => false], $this->pageProps($member)['can']);
        $this->assertEveryWriteIsForbidden($member);

        $this->actingAs($owner)->post(route('billing.cancel'))->assertRedirect();
        $this->assertSame(['cancel'], BaTeam::$calls);
    }

    public function test_a_host_gate_definition_decides_without_the_permissions_module(): void
    {
        config()->set('kinetix.permissions.enabled', false);
        Gate::define('billing.manage', fn (): bool => false);

        $owner = $this->member();
        $this->team->forceFill(['owner_id' => $owner->getKey()])->save();

        $this->assertFalse(BillingManager::allows('manage', $owner, $this->team));
        $this->assertTrue(BillingManager::allows('view', $owner, $this->team));
    }

    public function test_the_authorize_callback_overrides_the_built_in_rules(): void
    {
        config()->set('kinetix.billing.authorize', [BaBillingAccess::class, 'allows']);

        $member = $this->member();

        $this->actingAs($member)->get(route('billing.index'))->assertForbidden();
        $this->post(route('billing.cancel'))->assertRedirect();

        $this->assertSame(['cancel'], BaTeam::$calls);
    }

    public function test_a_user_billed_as_themselves_needs_no_permission(): void
    {
        $user = $this->member();

        $this->assertTrue(BillingManager::allows('view', $user, $user));
        $this->assertTrue(BillingManager::allows('manage', $user, $user));
        $this->assertFalse(BillingManager::allows('manage', $user, $this->team));
    }

    #[DefineEnvironment('withTeamBilling')]
    public function test_a_bound_team_the_user_is_not_in_is_a_404_on_every_billing_route(): void
    {
        config()->set('kinetix.billing.resolve_billable', null);
        Route::bind('team', fn (string $id): BaTeam => BaTeam::query()->findOrFail($id));

        $admin = $this->member('admin', $this->otherTeam);
        $admin->teams()->attach($this->otherTeam);

        $this->actingAs($admin);
        $team = ['team' => $this->team->getKey()];

        $this->get(route('billing.index', $team))->assertNotFound();
        $this->get(route('billing.invoices.download', [...$team, 'id' => 'in_1']))->assertNotFound();
        $this->post(route('billing.subscribe', $team), ['plan_slug' => 'pro'])->assertNotFound();
        $this->post(route('billing.cancel', $team))->assertNotFound();

        $this->assertSame([], BaTeam::$calls);

        // Their own team resolves (and the role there lets them in).
        $this->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Version', '1')
            ->get(route('billing.index', ['team' => $this->otherTeam->getKey()]))
            ->assertOk();
    }
}
