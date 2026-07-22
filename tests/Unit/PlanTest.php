<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Billing\Plan;
use Happones\Kinetix\Data\PlanData;
use Happones\Kinetix\Tests\TestCase;

class PlanTest extends TestCase
{
    private function plan(array $features = []): Plan
    {
        return new Plan([
            'name'          => 'Pro',
            'monthly_price' => 29.00,
            'yearly_price'  => 290.00,
            'features'      => $features,
        ]);
    }

    public function test_feature_value_resolves_dot_path(): void
    {
        $plan = $this->plan(['usage' => ['seats' => 5], 'capabilities' => ['api' => true]]);

        $this->assertSame(5, $plan->featureValue('usage.seats'));
        $this->assertTrue($plan->featureValue('capabilities.api'));
        $this->assertSame('fallback', $plan->featureValue('missing.key', 'fallback'));
    }

    public function test_can_use_feature_handles_bools_arrays_and_truthiness(): void
    {
        $plan = $this->plan([
            'capabilities' => ['api' => true, 'sso' => false],
            'channels'     => ['email', 'slack'],
            'empty'        => [],
            'seats'        => 0,
        ]);

        $this->assertTrue($plan->canUseFeature('capabilities.api'));
        $this->assertFalse($plan->canUseFeature('capabilities.sso'));
        $this->assertTrue($plan->canUseFeature('channels'));
        $this->assertFalse($plan->canUseFeature('empty'));
        $this->assertFalse($plan->canUseFeature('seats'));
        $this->assertFalse($plan->canUseFeature('does.not.exist'));
    }

    public function test_has_reached_limit_treats_null_as_unlimited(): void
    {
        $plan = $this->plan(['usage' => ['projects' => 3, 'members' => null]]);

        $this->assertFalse($plan->hasReachedLimit('usage.projects', 2));
        $this->assertTrue($plan->hasReachedLimit('usage.projects', 3));
        $this->assertTrue($plan->hasReachedLimit('usage.projects', 4));
        $this->assertFalse($plan->hasReachedLimit('usage.members', 9999));
        $this->assertFalse($plan->hasReachedLimit('usage.missing', 9999));
    }

    public function test_remaining_limit_floors_at_zero_and_null_means_unlimited(): void
    {
        $plan = $this->plan(['usage' => ['projects' => 3, 'members' => null]]);

        $this->assertSame(3, $plan->remainingLimit('usage.projects', 0));
        $this->assertSame(1, $plan->remainingLimit('usage.projects', 2));
        $this->assertSame(0, $plan->remainingLimit('usage.projects', 3));
        $this->assertSame(0, $plan->remainingLimit('usage.projects', 99));
        $this->assertNull($plan->remainingLimit('usage.members', 9999));
        $this->assertNull($plan->remainingLimit('usage.missing', 9999));
    }

    public function test_pricing_helpers(): void
    {
        $plan = $this->plan();

        $this->assertSame(29.0, $plan->priceFor('monthly'));
        $this->assertSame(290.0, $plan->priceFor('yearly'));
        $this->assertFalse($plan->isFree());

        $free = new Plan(['name' => 'Hobby', 'monthly_price' => 0]);
        $this->assertTrue($free->isFree());
    }

    public function test_stripe_price_id_by_cycle(): void
    {
        $plan = new Plan([
            'name'                    => 'Pro',
            'stripe_monthly_price_id' => 'price_m',
            'stripe_yearly_price_id'  => 'price_y',
        ]);

        $this->assertSame('price_m', $plan->stripePriceId('monthly'));
        $this->assertSame('price_y', $plan->stripePriceId('yearly'));
    }

    public function test_plan_data_dto_maps_from_plan(): void
    {
        $plan              = $this->plan(['usage' => ['seats' => 5]]);
        $plan->slug        = 'pro';
        $plan->is_featured = true;

        $data = PlanData::fromPlan($plan);

        $this->assertSame('Pro', $data->name);
        $this->assertSame('pro', $data->slug);
        $this->assertSame(29.0, $data->monthlyPrice);
        $this->assertSame(290.0, $data->yearlyPrice);
        $this->assertSame(['usage' => ['seats' => 5]], $data->features);
        $this->assertTrue($data->isFeatured);
        $this->assertFalse($data->isFree);
    }
}
