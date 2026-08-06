<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Billing\Concerns\EnforcesPlanLimits;
use Happones\Kinetix\Billing\Concerns\HasPlan;
use Happones\Kinetix\Billing\Exceptions\PlanLimitExceededException;
use Happones\Kinetix\Billing\Plan;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class PgUser extends Authenticatable
{
    use HasPlan;

    protected $table = 'pg_users';

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

class PgProject extends Model
{
    use EnforcesPlanLimits;

    protected $table = 'pg_projects';

    public $timestamps = false;

    protected $guarded = [];
}

class PlanGatingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('pg_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        Schema::create('pg_projects', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('pg_user_id')->nullable();
            $table->string('name');
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

        config()->set('kinetix.billing.enabled', true);
        config()->set('kinetix.billing.billable', PgUser::class);
    }

    private function freePlan(array $features): Plan
    {
        return Plan::create([
            'name'     => 'Free',
            'is_free'  => true,
            'features' => $features,
        ]);
    }

    public function test_plan_allows_and_limits_read_the_namespaced_features(): void
    {
        $this->freePlan([
            'capabilities' => ['api' => true, 'sso' => false],
            'usage'        => ['projects' => 2],
        ]);

        $user = PgUser::create(['name' => 'Jane']);

        $this->assertTrue($user->planAllows('api'));
        $this->assertFalse($user->planAllows('sso'));
        $this->assertFalse($user->planAllows('missing'));

        $this->assertSame(2, $user->planLimit('projects'));
        $this->assertNull($user->planLimit('missing'));

        $this->assertTrue($user->isWithinPlanLimit('projects', 1));
        $this->assertFalse($user->isWithinPlanLimit('projects', 2));
        // No limit declared = unlimited.
        $this->assertTrue($user->isWithinPlanLimit('missing', 999));
    }

    public function test_the_plan_capability_middleware_allows_and_denies(): void
    {
        Route::middleware(['web', 'kinetix.plan:api'])
            ->get('/pg-api', fn () => response()->json(['ok' => true]));

        $this->freePlan(['capabilities' => ['api' => false]]);
        $user = PgUser::create(['name' => 'Jane']);

        // Denied: JSON gets the 403.
        $this->actingAs($user)->getJson('/pg-api')->assertForbidden();

        // Denied WEB request with an upgrade URL: the upsell redirect.
        config()->set('kinetix.billing.upgrade_url', '/billing');
        $this->actingAs($user)->get('/pg-api')
            ->assertRedirect('/billing')
            ->assertSessionHas('kinetix_toast');

        // Allowed once the plan grants the capability.
        Plan::query()->update(['features' => json_encode(['capabilities' => ['api' => true]])]);
        $this->actingAs($user)->getJson('/pg-api')->assertOk();
    }

    public function test_the_plan_capability_middleware_denies_guests_without_erroring(): void
    {
        Route::middleware(['web', 'kinetix.plan:api'])
            ->get('/pg-guest', fn () => 'never');

        $this->getJson('/pg-guest')->assertForbidden();
    }

    public function test_enforces_plan_limits_blocks_creation_at_the_limit(): void
    {
        $this->freePlan(['usage' => ['pg_projects' => 2]]);
        $user = PgUser::create(['name' => 'Jane']);
        $this->actingAs($user);

        PgProject::create(['pg_user_id' => $user->id, 'name' => 'One']);
        PgProject::create(['pg_user_id' => $user->id, 'name' => 'Two']);

        try {
            PgProject::create(['pg_user_id' => $user->id, 'name' => 'Three']);
            $this->fail('Expected PlanLimitExceededException.');
        } catch (PlanLimitExceededException $e) {
            $this->assertSame('pg_projects', $e->limitKey);
            $this->assertSame(2, $e->limit);
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(2, PgProject::query()->count());
    }

    public function test_the_limit_only_counts_the_billables_own_records(): void
    {
        $this->freePlan(['usage' => ['pg_projects' => 2]]);
        $mine  = PgUser::create(['name' => 'Jane']);
        $other = PgUser::create(['name' => 'Rival']);

        // Another billable's records must not consume MY limit.
        PgProject::create(['pg_user_id' => $other->id, 'name' => 'A']);
        PgProject::create(['pg_user_id' => $other->id, 'name' => 'B']);

        $this->actingAs($mine);

        $created = PgProject::create(['pg_user_id' => $mine->id, 'name' => 'Mine']);

        $this->assertTrue($created->exists);
    }

    public function test_an_unlimited_plan_and_a_billing_less_environment_never_block(): void
    {
        // Plan without a usage entry: unlimited.
        $this->freePlan(['capabilities' => ['api' => true]]);
        $this->actingAs(PgUser::create(['name' => 'Jane']));

        for ($i = 0; $i < 5; $i++) {
            PgProject::create(['name' => "P{$i}"]);
        }

        $this->assertSame(5, PgProject::query()->count());

        // No authenticated billable at all: the trait skips silently.
        auth()->logout();
        PgProject::create(['name' => 'Guestless']);
        $this->assertSame(6, PgProject::query()->count());
    }

    public function test_the_shared_billing_prop_carries_the_upgrade_url(): void
    {
        config()->set('kinetix.billing.upgrade_url', '/billing');

        $this->freePlan(['capabilities' => ['api' => true]]);
        $this->actingAs(PgUser::create(['name' => 'Jane']));

        $shared = Inertia::getShared('kinetix_billing');
        $state  = is_callable($shared) ? $shared() : $shared;

        $this->assertTrue($state['enabled']);
        $this->assertSame('/billing', $state['upgradeUrl']);
        $this->assertSame('free', $state['plan']['slug']);
    }
}
