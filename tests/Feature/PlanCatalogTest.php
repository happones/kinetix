<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Billing\Concerns\EnforcesPlanLimits;
use Happones\Kinetix\Billing\Concerns\HasPlan;
use Happones\Kinetix\Billing\Plan;
use Happones\Kinetix\Billing\PlanCatalog;
use Happones\Kinetix\Support\Memo;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CatalogUser extends Authenticatable
{
    use HasPlan;

    protected $table = 'catalog_users';

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

class CatalogProject extends Model
{
    use EnforcesPlanLimits;

    protected $table = 'catalog_projects';

    public $timestamps = false;

    protected $guarded = [];

    public function planLimitKey(): string
    {
        return 'catalog_projects';
    }
}

/**
 * The `plans` table is read through {@see PlanCatalog}, so plan gating costs
 * ONE query per request no matter how many things are gated — the N+1 that
 * made every `planAllows()` / `planLimit()` / plan-gated feature flag re-query
 * the table.
 */
class PlanCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('catalog_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        Schema::create('catalog_projects', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('catalog_user_id')->nullable();
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

        config()->set('kinetix.billing.enabled', true);
        config()->set('kinetix.billing.billable', CatalogUser::class);
    }

    protected function tearDown(): void
    {
        PlanCatalog::flush();
        Memo::flush();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $features
     */
    private function freePlan(array $features = []): Plan
    {
        return Plan::create([
            'name'     => 'Free',
            'is_free'  => true,
            'features' => $features,
        ]);
    }

    /**
     * Count the `plans` queries a callback runs.
     */
    private function planQueriesDuring(callable $callback): int
    {
        $count = 0;

        DB::listen(function ($query) use (&$count): void {
            if (str_contains($query->sql, '"plans"') || str_contains($query->sql, '`plans`')) {
                $count++;
            }
        });

        $callback();

        return $count;
    }

    public function test_gating_many_things_costs_one_plans_query(): void
    {
        $this->freePlan([
            'capabilities' => ['api' => true, 'sso' => false],
            'usage'        => ['projects' => 10],
        ]);

        $user = CatalogUser::create(['name' => 'Jane']);

        PlanCatalog::flush();

        $queries = $this->planQueriesDuring(function () use ($user): void {
            // The shape of a page that gates a dozen things: each of these
            // used to re-run currentPlan(), and each currentPlan() a query.
            for ($i = 0; $i < 12; $i++) {
                $user->planAllows('api');
                $user->planAllows('sso');
                $user->planLimit('projects');
                $user->isWithinPlanLimit('projects', 3);
            }
        });

        $this->assertSame(1, $queries);
    }

    public function test_creating_many_records_resolves_the_plan_once(): void
    {
        $this->freePlan(['usage' => ['catalog_projects' => 100]]);

        $user = CatalogUser::create(['name' => 'Jane']);
        $this->actingAs($user);

        PlanCatalog::flush();

        // EnforcesPlanLimits runs on every `creating` event — the bulk-insert
        // case where a per-record plan query is most expensive.
        $queries = $this->planQueriesDuring(function () use ($user): void {
            for ($i = 0; $i < 20; $i++) {
                CatalogProject::create(['catalog_user_id' => $user->id, 'name' => "P{$i}"]);
            }
        });

        $this->assertSame(1, $queries);
        $this->assertSame(20, CatalogProject::query()->count());
    }

    public function test_a_plan_write_through_the_model_invalidates_the_catalog(): void
    {
        $plan = $this->freePlan(['capabilities' => ['api' => false]]);
        $user = CatalogUser::create(['name' => 'Jane']);

        $this->assertFalse($user->planAllows('api'));

        $plan->update(['features' => ['capabilities' => ['api' => true]]]);

        $this->assertTrue($user->planAllows('api'));
    }

    public function test_a_bulk_update_invalidates_the_catalog(): void
    {
        $this->freePlan(['capabilities' => ['api' => false]]);
        $user = CatalogUser::create(['name' => 'Jane']);

        $this->assertFalse($user->planAllows('api'));

        // Bulk writes fire no model events — PlanQueryBuilder flushes instead.
        Plan::query()->update(['features' => json_encode(['capabilities' => ['api' => true]])]);

        $this->assertTrue($user->planAllows('api'));
    }

    public function test_deleting_a_plan_invalidates_the_catalog(): void
    {
        $plan = $this->freePlan(['capabilities' => ['api' => true]]);
        $user = CatalogUser::create(['name' => 'Jane']);

        $this->assertTrue($user->planAllows('api'));

        $plan->delete();

        // No plan resolves at all: capabilities fail CLOSED, limits stay open.
        $this->assertNull($user->currentPlan());
        $this->assertFalse($user->planAllows('api'));
        $this->assertNull($user->planLimit('projects'));
    }

    public function test_the_free_fallback_keeps_active_and_ordered_semantics(): void
    {
        // Inactive free plans are never the fallback…
        Plan::create(['name' => 'Legacy', 'slug' => 'legacy', 'is_free' => true, 'is_active' => false, 'sort_order' => 0]);
        // …and among active ones, display order wins.
        Plan::create(['name' => 'Second', 'slug' => 'second', 'is_free' => true, 'sort_order' => 5]);
        Plan::create(['name' => 'First', 'slug' => 'first', 'is_free' => true, 'sort_order' => 1]);
        // A paid plan is not a free fallback.
        Plan::create(['name' => 'Pro', 'slug' => 'pro', 'monthly_price' => 20, 'sort_order' => 0]);

        $this->assertSame('first', PlanCatalog::free()?->slug);
    }

    public function test_a_blank_price_id_never_matches_a_plan(): void
    {
        Plan::create([
            'name'                    => 'Broken',
            'slug'                    => 'broken',
            'stripe_monthly_price_id' => '',
            'stripe_yearly_price_id'  => '',
        ]);

        $this->assertNull(PlanCatalog::byPriceId(''));
        $this->assertNull(PlanCatalog::byPriceId(null));
    }

    public function test_forget_current_plan_reresolves(): void
    {
        $this->freePlan(['capabilities' => ['api' => false]]);
        $user = CatalogUser::create(['name' => 'Jane']);

        $this->assertFalse($user->planAllows('api'));

        // A write Eloquent never sees: only an explicit forget recovers.
        DB::table('plans')->update(['features' => json_encode(['capabilities' => ['api' => true]])]);

        $this->assertFalse($user->planAllows('api'), 'the memo still answers');

        PlanCatalog::flush();
        $user->forgetCurrentPlan();

        $this->assertTrue($user->planAllows('api'));
    }
}
