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
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class FakeStripeSubscription
{
    public string $stripe_status = 'active';

    public ?object $ends_at = null;

    public ?string $stripe_price = 'price_pro_m';

    public ?Carbon $trial_ends_at = null;

    public bool $isOnTrial = false;

    public array $calls = [];

    public function __construct(public bool $grace = false) {}

    public function onTrial(): bool
    {
        return $this->isOnTrial;
    }

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
    public ?int $trialDays = null;

    public function __construct(public FakeBillable $billable, public string $price) {}

    public function trialDays(int $days): self
    {
        $this->trialDays = $days;

        return $this;
    }

    public function create(?string $paymentMethod = null): void
    {
        $trialString             = $this->trialDays !== null ? ":trial-{$this->trialDays}" : '';
        $this->billable->calls[] = "create:{$this->price}:{$paymentMethod}{$trialString}";
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

    public bool $isGenericTrial = false;

    public ?Carbon $trial_ends_at = null;

    public array $calls = [];

    public function onGenericTrial(): bool
    {
        return $this->isGenericTrial;
    }

    public function trialEndsAt(string $type = 'default'): ?Carbon
    {
        return $this->trial_ends_at;
    }

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
            $table->unsignedInteger('trial_days')->nullable();
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

    public function test_resolve_handles_string_team_route_parameter(): void
    {
        $team = FakeBillable::create();

        $request = request();
        $request->setRouteResolver(function () use ($team) {
            return new class($team->id)
            {
                public function __construct(protected $id) {}

                public function parameter(string $name, $default = null)
                {
                    return $name === 'team' ? (string) $this->id : null;
                }
            };
        });

        config([
            'kinetix.billing.teams'    => true,
            'kinetix.billing.billable' => FakeBillable::class,
        ]);

        $manager = BillingManager::resolve();
        $this->assertInstanceOf(FakeBillable::class, $manager->billable());
        $this->assertEquals($team->id, $manager->billable()->id);
    }

    public function test_subscription_data_includes_trial_information(): void
    {
        config(['kinetix.billing.trial_generic' => true]);

        $billable                 = new FakeBillable;
        $billable->isGenericTrial = true;
        $billable->trial_ends_at  = now()->addDays(10);

        $data = BillingManager::for($billable)->subscriptionData();
        $this->assertTrue($data['onTrial']);
        $this->assertTrue($data['onGenericTrial']);
        $this->assertEquals($billable->trial_ends_at->toIso8601String(), $data['trialEndsAt']);

        config(['kinetix.billing.trial_generic' => false]);

        $billable2                     = new FakeBillable;
        $billable2->sub                = new FakeStripeSubscription;
        $billable2->sub->isOnTrial     = true;
        $billable2->sub->trial_ends_at = now()->addDays(5);

        $data2 = BillingManager::for($billable2)->subscriptionData();
        $this->assertTrue($data2['onTrial']);
        $this->assertFalse($data2['onGenericTrial']);
        $this->assertEquals($billable2->sub->trial_ends_at->toIso8601String(), $data2['trialEndsAt']);
    }

    public function test_subscribe_passes_trial_days_from_plan(): void
    {
        config(['kinetix.billing.trial_generic' => false]);

        $billable = new FakeBillable;
        $plan     = Plan::create([
            'name'                    => 'Trial Plan',
            'slug'                    => 'trial-plan',
            'monthly_price'           => 19,
            'stripe_monthly_price_id' => 'price_trial_m',
            'trial_days'              => 14,
        ]);

        BillingManager::for($billable)->subscribe('trial-plan', 'pm_card');

        $this->assertContains('create:price_trial_m:pm_card:trial-14', $billable->calls);
    }

    public function test_subscribe_does_not_pass_trial_days_from_plan_if_trial_generic_is_true(): void
    {
        config(['kinetix.billing.trial_generic' => true]);

        $billable = new FakeBillable;
        $plan     = Plan::create([
            'name'                    => 'Trial Plan 2',
            'slug'                    => 'trial-plan-2',
            'monthly_price'           => 19,
            'stripe_monthly_price_id' => 'price_trial_m2',
            'trial_days'              => 14,
        ]);

        BillingManager::for($billable)->subscribe('trial-plan-2', 'pm_card');

        $this->assertContains('create:price_trial_m2:pm_card', $billable->calls);
        $this->assertNotContains('create:price_trial_m2:pm_card:trial-14', $billable->calls);
    }
}
