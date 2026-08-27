<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Billing\Concerns\HasPlan;
use Happones\Kinetix\Billing\Plan;
use Happones\Kinetix\Billing\PlanCatalog;
use Happones\Kinetix\Entitlements\EntitlementRegistry;
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
use Inertia\Inertia;

class IsolationUser extends Authenticatable
{
    use HasPlan;

    protected $table = 'isolation_users';

    public $timestamps = false;

    protected $guarded = [];

    public function subscription(string $type = 'default'): mixed
    {
        return null;
    }

    public function onGenericTrial(): bool
    {
        return false;
    }
}

/**
 * The four gating layers are independent modules, each opt-in. Composing them
 * behind Entitlements must not have coupled them: an app that uses ONLY
 * permissions must keep working with no billing tables and no flags declared,
 * an app that uses ONLY billing must keep working with spatie absent, and so
 * on. Every combination below is a real deployment shape.
 *
 * Note this suite deliberately does NOT register spatie's service provider —
 * so everything it asserts about billing, flags and entitlements holds in an
 * app where spatie/laravel-permission is not even installed.
 */
class GatingModuleIsolationTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Everything OFF by default — each test turns on only what it needs.
        $app['config']->set('kinetix.permissions.enabled', false);
        $app['config']->set('kinetix.billing.enabled', false);
        $app['config']->set('kinetix.features.enabled', false);
        $app['config']->set('kinetix.features.driver', 'native');
        $app['config']->set('kinetix.entitlements.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('isolation_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        config()->set('kinetix.billing.billable', IsolationUser::class);
    }

    protected function tearDown(): void
    {
        app(EntitlementRegistry::class)->reset();
        PlanCatalog::flush();
        Memo::flush();

        parent::tearDown();
    }

    private function createPlansTable(): void
    {
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
    }

    private function user(): IsolationUser
    {
        $user = IsolationUser::create(['name' => 'Jane']);
        $this->actingAs($user);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function shared(string $key): array
    {
        /** @var callable $share */
        $share = Inertia::getShared($key);

        return (array) value($share);
    }

    // -- Permissions alone ---------------------------------------------------

    public function test_gate_checks_work_with_billing_flags_and_entitlements_off(): void
    {
        // No plans table exists in this test at all — a permissions-only app
        // never created one.
        Gate::define('posts.update', fn (): bool => true);
        Gate::define('posts.delete', fn (): bool => false);

        $user = $this->user();

        $this->assertTrue(Gate::forUser($user)->allows('posts.update'));
        $this->assertFalse(Gate::forUser($user)->allows('posts.delete'));
    }

    public function test_a_can_middleware_route_works_with_only_permissions(): void
    {
        Gate::define('posts.update', fn (): bool => true);

        Route::middleware(['web', 'can:posts.update'])
            ->get('/iso-can', fn () => response()->json(['ok' => true]));

        $this->actingAs($this->user())->getJson('/iso-can')->assertOk();
    }

    public function test_no_plans_query_is_ever_made_when_billing_is_off(): void
    {
        Gate::define('posts.update', fn (): bool => true);
        $user = $this->user();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        Gate::forUser($user)->allows('posts.update');
        $this->shared('kinetix_permissions');
        $this->shared('kinetix_features');
        $this->shared('kinetix_entitlements');

        $touchedPlans = array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'plans'));

        $this->assertSame([], $touchedPlans, 'a billing-less app must never touch the plans table');
    }

    // -- Billing alone -------------------------------------------------------

    public function test_plan_gating_works_with_permissions_flags_and_entitlements_off(): void
    {
        $this->createPlansTable();
        config()->set('kinetix.billing.enabled', true);

        Plan::create([
            'name'     => 'Free',
            'is_free'  => true,
            'features' => ['capabilities' => ['api' => true], 'usage' => ['projects' => 5]],
        ]);

        $user = $this->user();

        // spatie is not even registered in this suite — plan gating must not
        // care.
        $this->assertTrue($user->planAllows('api'));
        $this->assertFalse($user->planAllows('sso'));
        $this->assertSame(5, $user->planLimit('projects'));
        $this->assertTrue($user->isWithinPlanLimit('projects', 2));
    }

    public function test_the_plan_middleware_works_with_only_billing(): void
    {
        $this->createPlansTable();
        config()->set('kinetix.billing.enabled', true);

        Plan::create([
            'name'     => 'Free',
            'is_free'  => true,
            'features' => ['capabilities' => ['api' => true, 'sso' => false]],
        ]);

        Route::middleware(['web', 'kinetix.plan:api'])
            ->get('/iso-plan-ok', fn () => response()->json(['ok' => true]));
        Route::middleware(['web', 'kinetix.plan:sso'])
            ->get('/iso-plan-no', fn () => 'never');

        $this->actingAs($this->user());

        $this->get('/iso-plan-ok')->assertOk();
        $this->getJson('/iso-plan-no')->assertForbidden();
    }

    public function test_the_billing_share_is_inert_when_billing_is_off(): void
    {
        $this->user();

        $data = $this->shared('kinetix_billing');

        $this->assertFalse($data['enabled']);
        $this->assertNull($data['plan']);
    }

    // -- Feature flags alone -------------------------------------------------

    public function test_flags_work_with_permissions_billing_and_entitlements_off(): void
    {
        config()->set('kinetix.features.enabled', true);

        KinetixFeatures::define('new-nav', true);
        KinetixFeatures::define('beta', false);

        $this->user();

        $this->assertTrue(KinetixFeatures::active('new-nav'));
        $this->assertTrue(KinetixFeatures::inactive('beta'));
        $this->assertSame(['new-nav' => true, 'beta' => false], $this->shared('kinetix_features'));
    }

    public function test_the_feature_middleware_works_with_only_flags(): void
    {
        config()->set('kinetix.features.enabled', true);
        KinetixFeatures::define('beta', false);

        Route::middleware(['web', 'kinetix.feature:beta'])
            ->get('/iso-flag', fn () => 'never');

        $this->actingAs($this->user())->get('/iso-flag')->assertNotFound();
    }

    // -- Everything off ------------------------------------------------------

    public function test_every_gating_share_is_inert_with_all_modules_off(): void
    {
        $this->user();

        $this->assertFalse($this->shared('kinetix_permissions')['enabled']);
        $this->assertFalse($this->shared('kinetix_billing')['enabled']);
        $this->assertSame([], $this->shared('kinetix_features'));
        $this->assertFalse($this->shared('kinetix_entitlements')['enabled']);
    }

    public function test_the_request_state_reset_runs_without_any_module_enabled(): void
    {
        // registerStateReset() runs at boot on every app — it must not query,
        // touch the plans table, or care that billing was never installed.
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        PlanCatalog::flushMemo();
        Memo::flush();

        $this->assertSame([], $queries);
    }
}
