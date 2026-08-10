<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Billing\Concerns\HasPlan;
use Happones\Kinetix\Billing\Plan;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal stand-in for a Cashier subscription record.
 */
class FakeSubscription
{
    public function __construct(public ?string $stripe_price) {}
}

class BillableUser extends Authenticatable
{
    use HasPlan;

    protected $table = 'billable_users';

    public $timestamps = false;

    protected $guarded = [];

    public ?FakeSubscription $fakeSubscription = null;

    public ?string $trial_plan = null;

    public function subscription(string $type = 'default'): ?FakeSubscription
    {
        return $this->fakeSubscription;
    }

    public function onGenericTrial(): bool
    {
        return false;
    }
}

class HasPlanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('billable_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
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
    }

    public function test_slug_is_generated_from_name_on_save(): void
    {
        $plan = Plan::create(['name' => 'Pro Team', 'monthly_price' => 10]);

        $this->assertSame('pro-team', $plan->fresh()->slug);
    }

    public function test_scopes_filter_and_order(): void
    {
        Plan::create(['name' => 'B', 'monthly_price' => 20, 'sort_order' => 2, 'is_active' => true, 'is_featured' => false]);
        Plan::create(['name' => 'A', 'monthly_price' => 10, 'sort_order' => 1, 'is_active' => true, 'is_featured' => true]);
        Plan::create(['name' => 'C', 'monthly_price' => 30, 'sort_order' => 3, 'is_active' => false, 'is_featured' => false]);

        $active = Plan::query()->active()->ordered()->get();
        $this->assertSame(['A', 'B'], $active->pluck('name')->all());

        $featured = Plan::query()->featured()->get();
        $this->assertSame(['A'], $featured->pluck('name')->all());
    }

    public function test_current_plan_is_null_without_subscription(): void
    {
        $user = BillableUser::create(['name' => 'Jane']);

        $this->assertNull($user->currentPlan());
        $this->assertFalse($user->onPlan('pro'));
        $this->assertFalse($user->canUseFeature('capabilities.api'));
        $this->assertSame('fallback', $user->planFeature('x', 'fallback'));
    }

    public function test_current_plan_resolves_from_subscription_price(): void
    {
        $plan = Plan::create([
            'name'                    => 'Pro',
            'monthly_price'           => 29,
            'stripe_monthly_price_id' => 'price_pro_m',
            'features'                => ['capabilities' => ['api' => true], 'usage' => ['seats' => 3]],
        ]);

        $user                   = BillableUser::create(['name' => 'Jane']);
        $user->fakeSubscription = new FakeSubscription('price_pro_m');

        $this->assertTrue($user->currentPlan()->is($plan));
        $this->assertTrue($user->onPlan('pro'));
        $this->assertTrue($user->canUseFeature('capabilities.api'));
        $this->assertSame(3, $user->planFeature('usage.seats'));
        $this->assertTrue($user->hasReachedPlanLimit('usage.seats', 3));
        $this->assertFalse($user->hasReachedPlanLimit('usage.seats', 1));
    }

    public function test_remaining_plan_limit_counts_down_and_null_means_unlimited(): void
    {
        Plan::create([
            'name'                    => 'Pro',
            'monthly_price'           => 29,
            'stripe_monthly_price_id' => 'price_pro_m',
            'features'                => ['usage' => ['products' => 10, 'members' => null]],
        ]);

        $user                   = BillableUser::create(['name' => 'Jane']);
        $user->fakeSubscription = new FakeSubscription('price_pro_m');

        $this->assertSame(10, $user->remainingPlanLimit('usage.products', 0));
        $this->assertSame(2, $user->remainingPlanLimit('usage.products', 8));
        $this->assertSame(0, $user->remainingPlanLimit('usage.products', 25));
        // Null limit — and a missing path — mean unlimited.
        $this->assertNull($user->remainingPlanLimit('usage.members', 500));
        $this->assertNull($user->remainingPlanLimit('usage.missing', 500));

        // Without any plan (no subscription, no free plan) nothing is limited.
        $free = BillableUser::create(['name' => 'No plan']);
        $this->assertNull($free->remainingPlanLimit('usage.products', 99));
    }

    public function test_a_blank_subscription_price_never_matches_a_plan(): void
    {
        // A plan seeded/imported with empty price columns instead of NULL.
        Plan::create([
            'name'                    => 'Pro',
            'monthly_price'           => 29,
            'stripe_monthly_price_id' => '',
            'stripe_yearly_price_id'  => '',
            'features'                => ['capabilities' => ['api' => true]],
        ]);

        $free = Plan::create(['name' => 'Free', 'monthly_price' => 0, 'is_free' => true]);

        $user                   = BillableUser::create(['name' => 'Jane']);
        $user->fakeSubscription = new FakeSubscription('');

        // Matching '' against '' would hand out Pro's capabilities for free.
        $this->assertTrue($user->currentPlan()->is($free));
        $this->assertFalse($user->canUseFeature('capabilities.api'));
    }

    public function test_a_blank_trial_plan_slug_is_ignored(): void
    {
        config(['kinetix.billing.trial_generic' => true]);

        $free = Plan::create(['name' => 'Free', 'monthly_price' => 0, 'is_free' => true]);

        $user = new class extends BillableUser
        {
            protected $table = 'billable_users';

            public function onGenericTrial(): bool
            {
                return true;
            }
        };

        $user->fill(['name' => 'Jane'])->save();
        $user->trial_plan = '';

        $this->assertTrue($user->currentPlan()->is($free));
    }

    public function test_current_plan_matches_yearly_price_id(): void
    {
        $plan = Plan::create([
            'name'                   => 'Pro',
            'monthly_price'          => 29,
            'stripe_yearly_price_id' => 'price_pro_y',
        ]);

        $user                   = BillableUser::create(['name' => 'Jane']);
        $user->fakeSubscription = new FakeSubscription('price_pro_y');

        $this->assertTrue($user->currentPlan()->is($plan));
    }

    public function test_current_plan_resolves_from_trial_plan_when_on_generic_trial(): void
    {
        config(['kinetix.billing.trial_generic' => true]);

        $plan = Plan::create([
            'name'          => 'Pro',
            'monthly_price' => 29,
        ]);

        $user = new class extends BillableUser
        {
            public function onGenericTrial(): bool
            {
                return true;
            }
        };
        $user->trial_plan       = 'pro';
        $user->fakeSubscription = new FakeSubscription('price_pro_m');

        $this->assertTrue($user->currentPlan()->is($plan));
    }
}
