<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Billing\BillingManager;
use Happones\Kinetix\Billing\Plan;
use Happones\Kinetix\Data\PlanData;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class FakeStripeSubscription
{
    public string $stripe_status = 'active';

    public ?object $ends_at = null;

    public ?string $stripe_price = 'price_pro_m';

    public array $calls = [];

    public function __construct(public bool $grace = false) {}

    public function active(): bool
    {
        return true;
    }

    public function onGracePeriod(): bool
    {
        return $this->grace;
    }

    public function cancel(): void
    {
        $this->calls[] = 'cancel';
    }

    public function resume(): void
    {
        $this->calls[] = 'resume';
    }

    public function swap(string $price): void
    {
        $this->calls[] = "swap:{$price}";
    }
}

class FakeSubscriptionBuilder
{
    public function __construct(public FakeBillable $billable, public string $price) {}

    public function create(?string $paymentMethod = null): void
    {
        $this->billable->calls[] = "create:{$this->price}:{$paymentMethod}";
    }
}

class FakeBillable extends Model
{
    protected $table = 'fake_billables';

    public $timestamps = false;

    protected $guarded = [];

    public ?string $stripe_id = 'cus_123';

    public ?FakeStripeSubscription $sub = null;

    public bool $isSubscribed = false;

    public array $calls = [];

    public function subscribed(string $type = 'default'): bool
    {
        return $this->isSubscribed;
    }

    public function subscription(string $type = 'default'): ?FakeStripeSubscription
    {
        return $this->sub;
    }

    public function newSubscription(string $type, string $price): FakeSubscriptionBuilder
    {
        return new FakeSubscriptionBuilder($this, $price);
    }

    public function paymentMethods(): Collection
    {
        return collect([
            (object) [
                'id'   => 'pm_1',
                'card' => (object) ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030],
            ],
        ]);
    }

    public function defaultPaymentMethod(): ?object
    {
        return (object) ['id' => 'pm_1'];
    }
}

class BillingManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('fake_billables', function (Blueprint $table) {
            $table->increments('id');
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
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Plan::create(['name' => 'Free', 'slug' => 'free', 'monthly_price' => 0, 'sort_order' => 0]);
        Plan::create([
            'name'                    => 'Pro',
            'slug'                    => 'pro',
            'monthly_price'           => 29,
            'stripe_monthly_price_id' => 'price_pro_m',
            'stripe_yearly_price_id'  => 'price_pro_y',
            'sort_order'              => 1,
        ]);
    }

    public function test_plans_returns_ordered_dtos(): void
    {
        $plans = BillingManager::for(new FakeBillable)->plans();

        $this->assertCount(2, $plans);
        $this->assertInstanceOf(PlanData::class, $plans->first());
        $this->assertSame(['free', 'pro'], $plans->pluck('slug')->all());
    }

    public function test_subscribe_to_free_plan_cancels_active_subscription(): void
    {
        $billable               = new FakeBillable;
        $billable->isSubscribed = true;
        $billable->sub          = new FakeStripeSubscription;

        BillingManager::for($billable)->subscribe('free');

        $this->assertContains('cancel', $billable->sub->calls);
    }

    public function test_subscribe_to_paid_plan_when_not_subscribed_creates(): void
    {
        $billable               = new FakeBillable;
        $billable->isSubscribed = false;

        BillingManager::for($billable)->subscribe('pro', 'pm_card', 'monthly');

        $this->assertContains('create:price_pro_m:pm_card', $billable->calls);
    }

    public function test_subscribe_paid_yearly_uses_yearly_price(): void
    {
        $billable = new FakeBillable;

        BillingManager::for($billable)->subscribe('pro', 'pm_card', 'yearly');

        $this->assertContains('create:price_pro_y:pm_card', $billable->calls);
    }

    public function test_subscribe_when_subscribed_swaps(): void
    {
        $billable               = new FakeBillable;
        $billable->isSubscribed = true;
        $billable->sub          = new FakeStripeSubscription;

        BillingManager::for($billable)->subscribe('pro');

        $this->assertContains('swap:price_pro_m', $billable->sub->calls);
    }

    public function test_subscribe_resumes_grace_period_before_swap(): void
    {
        $billable               = new FakeBillable;
        $billable->isSubscribed = true;
        $billable->sub          = new FakeStripeSubscription(grace: true);

        BillingManager::for($billable)->subscribe('pro');

        $this->assertSame(['resume', 'swap:price_pro_m'], $billable->sub->calls);
    }

    public function test_new_paid_subscription_requires_payment_method(): void
    {
        $billable = new FakeBillable;

        $this->expectException(RuntimeException::class);

        BillingManager::for($billable)->subscribe('pro');
    }

    public function test_payment_methods_are_mapped_to_camel_case(): void
    {
        $methods = BillingManager::for(new FakeBillable)->paymentMethods();

        $this->assertSame([
            'id'       => 'pm_1',
            'brand'    => 'visa',
            'last4'    => '4242',
            'expMonth' => 12,
            'expYear'  => 2030,
        ], $methods[0]);
    }

    public function test_subscription_data_shape(): void
    {
        $billable      = new FakeBillable;
        $billable->sub = new FakeStripeSubscription;

        $data = BillingManager::for($billable)->subscriptionData();

        $this->assertTrue($data['active']);
        $this->assertFalse($data['onGracePeriod']);
        $this->assertSame('active', $data['status']);
        $this->assertSame('price_pro_m', $data['stripePrice']);
    }

    public function test_cancel_and_resume_delegate_to_subscription(): void
    {
        $billable               = new FakeBillable;
        $billable->isSubscribed = true;
        $billable->sub          = new FakeStripeSubscription(grace: true);

        $manager = BillingManager::for($billable);
        $manager->cancel();
        $manager->resume();

        $this->assertContains('cancel', $billable->sub->calls);
        $this->assertContains('resume', $billable->sub->calls);
    }

    public function test_resolve_supports_team_billing_option(): void
    {
        $team = new FakeBillable;

        $user = new class extends User
        {
            public $currentTeam;
        };
        $user->currentTeam = $team;

        $this->actingAs($user);

        // Without team config, it resolves user
        config(['kinetix.billing.teams' => false]);
        $manager = BillingManager::resolve();
        $this->assertSame($user, $manager->billable());

        // With team config, it resolves the current team
        config(['kinetix.billing.teams' => true]);
        $manager2 = BillingManager::resolve();
        $this->assertSame($team, $manager2->billable());
    }
}
