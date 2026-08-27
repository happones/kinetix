<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Billing\Concerns\HasPlan;
use Happones\Kinetix\Billing\Plan;
use Happones\Kinetix\Billing\PlanCatalog;
use Happones\Kinetix\Entitlements\DenialReason;
use Happones\Kinetix\Entitlements\EntitlementRegistry;
use Happones\Kinetix\Entitlements\KinetixEntitlements;
use Happones\Kinetix\Features\KinetixFeatures;
use Happones\Kinetix\Support\Memo;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class EntUser extends Authenticatable
{
    use HasPlan;

    protected $table = 'ent_users';

    public $timestamps = false;

    protected $guarded = [];

    public function subscription(string $type = 'default'): mixed
    {
        return null; // falls back to the free plan
    }

    public function onGenericTrial(): bool
    {
        return false;
    }
}

/**
 * Entitlements compose the four gating layers under one declared name, and —
 * crucially — report WHICH one refused, so a flag denial can 404, a plan
 * denial can upsell and a permission denial can 403.
 */
class EntitlementsTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.features.enabled', true);
        // Pennant is a dev dependency here but has no store configured; the
        // native evaluator exercises the same FeatureManager contract.
        $app['config']->set('kinetix.features.driver', 'native');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ent_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
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

        config()->set('kinetix.billing.enabled', true);
        config()->set('kinetix.billing.billable', EntUser::class);
        config()->set('kinetix.entitlements.enabled', true);

        app(EntitlementRegistry::class)->reset();
    }

    protected function tearDown(): void
    {
        app(EntitlementRegistry::class)->reset();
        PlanCatalog::flush();
        Memo::flush();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $features
     */
    private function freePlan(array $features = []): Plan
    {
        return Plan::create(['name' => 'Free', 'is_free' => true, 'features' => $features]);
    }

    private function user(): EntUser
    {
        $user = EntUser::create(['name' => 'Jane']);
        $this->actingAs($user);

        return $user;
    }

    // -- Layer composition ---------------------------------------------------

    public function test_an_entitlement_with_no_layers_allows(): void
    {
        KinetixEntitlements::define('open.door');
        $this->user();

        $this->assertTrue(KinetixEntitlements::allows('open.door'));
    }

    public function test_an_undeclared_entitlement_is_denied_as_undefined(): void
    {
        $this->user();

        $verdict = KinetixEntitlements::check('never.declared');

        $this->assertFalse($verdict->allowed);
        $this->assertSame(DenialReason::Undefined, $verdict->reason);
        $this->assertSame(403, $verdict->status());
    }

    public function test_the_flag_layer_denies_with_a_flag_reason(): void
    {
        KinetixFeatures::define('beta', false);
        KinetixEntitlements::define('beta.thing')->flag('beta');
        $this->user();

        $verdict = KinetixEntitlements::check('beta.thing');

        $this->assertFalse($verdict->allowed);
        $this->assertSame(DenialReason::Flag, $verdict->reason);
        // An unreleased feature must look like it was never built.
        $this->assertSame(404, $verdict->status());
        $this->assertFalse($verdict->isUpsell());
    }

    public function test_the_plan_layer_denies_with_an_upsell(): void
    {
        $this->freePlan(['capabilities' => ['api' => false]]);
        KinetixEntitlements::define('api.use')->plan('api');
        $this->user();

        $verdict = KinetixEntitlements::check('api.use');

        $this->assertFalse($verdict->allowed);
        $this->assertSame(DenialReason::Plan, $verdict->reason);
        $this->assertSame(403, $verdict->status());
        $this->assertTrue($verdict->isUpsell());
    }

    public function test_the_limit_layer_denies_at_the_cap_and_reports_remaining(): void
    {
        $this->freePlan(['usage' => ['projects' => 3]]);
        $this->user();

        KinetixEntitlements::define('projects.create')
            ->limit('projects', fn (): int => 1);

        $under = KinetixEntitlements::check('projects.create');
        $this->assertTrue($under->allowed);
        $this->assertSame(2, $under->remaining);

        app(EntitlementRegistry::class)->reset();
        KinetixEntitlements::define('projects.create')
            ->limit('projects', fn (): int => 3);

        $at = KinetixEntitlements::check('projects.create');
        $this->assertFalse($at->allowed);
        $this->assertSame(DenialReason::Limit, $at->reason);
        $this->assertSame(0, $at->remaining);
        $this->assertTrue($at->isUpsell());
    }

    public function test_the_permission_layer_denies_with_a_permission_reason(): void
    {
        $this->freePlan(['capabilities' => ['api' => true]]);
        Gate::define('api.manage', fn (): bool => false);

        KinetixEntitlements::define('api.manage')->plan('api')->permission('api.manage');
        $this->user();

        $verdict = KinetixEntitlements::check('api.manage');

        $this->assertFalse($verdict->allowed);
        $this->assertSame(DenialReason::Permission, $verdict->reason);
        $this->assertFalse($verdict->isUpsell());
    }

    public function test_all_layers_passing_allows(): void
    {
        $this->freePlan(['capabilities' => ['api' => true], 'usage' => ['calls' => 10]]);
        KinetixFeatures::define('api-v2', true);
        Gate::define('api.manage', fn (): bool => true);

        KinetixEntitlements::define('api.manage')
            ->flag('api-v2')
            ->plan('api')
            ->limit('calls', fn (): int => 2)
            ->permission('api.manage');

        $this->user();

        $verdict = KinetixEntitlements::check('api.manage');

        $this->assertTrue($verdict->allowed);
        $this->assertNull($verdict->reason);
        $this->assertSame(8, $verdict->remaining);
    }

    // -- Order & short-circuiting -------------------------------------------

    public function test_the_flag_layer_short_circuits_before_the_plan(): void
    {
        $this->freePlan(['capabilities' => ['api' => false]]);
        KinetixFeatures::define('beta', false);
        KinetixEntitlements::define('beta.api')->flag('beta')->plan('api');
        $this->user();

        // Both would deny; the FLAG answers first, so the feature reads as
        // "not built here" rather than "buy the upgrade".
        $this->assertSame(DenialReason::Flag, KinetixEntitlements::check('beta.api')->reason);
    }

    public function test_the_permission_layer_is_never_reached_when_the_plan_denies(): void
    {
        $this->freePlan(['capabilities' => ['api' => false]]);
        $reached = false;

        Gate::define('api.manage', function () use (&$reached): bool {
            $reached = true;

            return true;
        });

        KinetixEntitlements::define('api.manage')->plan('api')->permission('api.manage');
        $this->user();

        KinetixEntitlements::check('api.manage');

        $this->assertFalse($reached, 'the per-user layer must not run once the tenant layer refused');
    }

    // -- Fail-open / fail-closed --------------------------------------------

    public function test_plan_layers_are_skipped_when_billing_is_disabled(): void
    {
        config()->set('kinetix.billing.enabled', false);

        KinetixEntitlements::define('api.use')->plan('api')->limit('calls', fn (): int => 999);
        $this->user();

        // No billing module = no plans to consult; a plan layer must not block
        // an app that never installed billing.
        $this->assertTrue(KinetixEntitlements::allows('api.use'));
    }

    public function test_a_capability_fails_closed_for_a_guest_while_a_limit_fails_open(): void
    {
        $this->freePlan(['capabilities' => ['api' => true]]);

        KinetixEntitlements::define('api.use')->plan('api');
        KinetixEntitlements::define('projects.create')->limit('projects', fn (): int => 999);

        // No authenticated user → no billable resolves.
        $this->assertSame(DenialReason::Plan, KinetixEntitlements::check('api.use')->reason);
        $this->assertTrue(KinetixEntitlements::allows('projects.create'));
    }

    // -- Memoization ---------------------------------------------------------

    public function test_repeated_checks_evaluate_once(): void
    {
        $this->freePlan(['capabilities' => ['api' => true]]);
        $calls = 0;

        Gate::define('api.manage', function () use (&$calls): bool {
            $calls++;

            return true;
        });

        KinetixEntitlements::define('api.manage')->plan('api')->permission('api.manage');
        $this->user();

        for ($i = 0; $i < 10; $i++) {
            KinetixEntitlements::allows('api.manage');
        }

        $this->assertSame(1, $calls);
    }

    public function test_checking_many_entitlements_costs_one_plans_query(): void
    {
        $this->freePlan(['capabilities' => ['a' => true, 'b' => true, 'c' => true]]);
        $user = $this->user();

        foreach (['a', 'b', 'c'] as $capability) {
            KinetixEntitlements::define("thing.{$capability}")->plan($capability);
        }

        PlanCatalog::flush();
        $user->forgetCurrentPlan();

        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, '"plans"')) {
                $queries++;
            }
        });

        foreach (['a', 'b', 'c'] as $capability) {
            KinetixEntitlements::allows("thing.{$capability}");
        }

        $this->assertSame(1, $queries);
    }

    // -- Middleware ----------------------------------------------------------

    public function test_the_middleware_404s_a_flag_denial(): void
    {
        KinetixFeatures::define('beta', false);
        KinetixEntitlements::define('beta.page')->flag('beta');

        Route::middleware(['web', 'kinetix.entitled:beta.page'])
            ->get('/ent-beta', fn () => 'never');

        $this->actingAs($this->user())->get('/ent-beta')->assertNotFound();
    }

    public function test_the_middleware_upsells_a_plan_denial_on_web(): void
    {
        $this->freePlan(['capabilities' => ['api' => false]]);
        KinetixEntitlements::define('api.use')->plan('api');
        config()->set('kinetix.billing.upgrade_url', '/billing');

        Route::middleware(['web', 'kinetix.entitled:api.use'])
            ->get('/ent-api', fn () => 'never');

        $this->actingAs($this->user())->get('/ent-api')
            ->assertRedirect('/billing')
            ->assertSessionHas('kinetix_toast');
    }

    public function test_the_middleware_403s_a_plan_denial_on_json(): void
    {
        $this->freePlan(['capabilities' => ['api' => false]]);
        KinetixEntitlements::define('api.use')->plan('api');
        config()->set('kinetix.billing.upgrade_url', '/billing');

        Route::middleware(['web', 'kinetix.entitled:api.use'])
            ->get('/ent-api-json', fn () => 'never');

        $this->actingAs($this->user())->getJson('/ent-api-json')->assertForbidden();
    }

    public function test_the_middleware_allows_and_accepts_several_entitlements(): void
    {
        $this->freePlan(['capabilities' => ['api' => true, 'sso' => true]]);
        KinetixEntitlements::define('api.use')->plan('api');
        KinetixEntitlements::define('sso.use')->plan('sso');

        Route::middleware(['web', 'kinetix.entitled:api.use,sso.use'])
            ->get('/ent-both', fn () => response()->json(['ok' => true]));

        $this->actingAs($this->user())->getJson('/ent-both')->assertOk();
    }

    // -- The Inertia share ---------------------------------------------------

    public function test_the_share_carries_verdicts_and_honors_shared_false(): void
    {
        $this->freePlan(['capabilities' => ['api' => false], 'usage' => ['projects' => 5]]);

        KinetixEntitlements::define('api.use')->plan('api');
        KinetixEntitlements::define('projects.create')->limit('projects', fn (): int => 2);
        KinetixEntitlements::define('expensive.thing')->plan('api')->shared(false);

        $this->user();

        $shared = app(EntitlementRegistry::class)->resolveShared();

        $this->assertFalse($shared['api.use']['allowed']);
        $this->assertSame('plan', $shared['api.use']['reason']);

        $this->assertTrue($shared['projects.create']['allowed']);
        $this->assertSame(3, $shared['projects.create']['remaining']);

        $this->assertArrayNotHasKey('expensive.thing', $shared);
    }

    public function test_define_is_additive_so_providers_can_contribute_layers(): void
    {
        $this->freePlan(['capabilities' => ['api' => true]]);
        Gate::define('api.manage', fn (): bool => false);

        KinetixEntitlements::define('api.use')->plan('api');
        // A second provider adds a layer to the same name.
        KinetixEntitlements::define('api.use')->permission('api.manage');

        $this->user();

        $this->assertSame(DenialReason::Permission, KinetixEntitlements::check('api.use')->reason);
    }
}
