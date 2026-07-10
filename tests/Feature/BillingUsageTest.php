<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Billing\BillingManager;
use Happones\Kinetix\Billing\Contracts\ProvidesUsageMetrics;
use Happones\Kinetix\Billing\Plan;
use Happones\Kinetix\Billing\UsageMetric;
use Happones\Kinetix\Data\UsageMetricData;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UsageFakeSubscription
{
    public array $calls = [];

    public function reportUsage(int $quantity = 1): void
    {
        $this->calls[] = "reportUsage:{$quantity}";
    }

    public function reportUsageFor(string $priceId, int $quantity = 1): void
    {
        $this->calls[] = "reportUsageFor:{$priceId}:{$quantity}";
    }
}

/** No interface — proves hybrid (duck-typed) detection. */
class DuckTypedUsageBillable extends Model
{
    protected $table = 'usage_billables';

    public $timestamps = false;

    protected $guarded = [];

    public ?UsageFakeSubscription $sub = null;

    public function subscription(string $type = 'default'): ?UsageFakeSubscription
    {
        return $this->sub;
    }

    public function meteredUsage(?Plan $plan): array
    {
        return [
            UsageMetric::make('api_calls')->label('API calls')->used(1200)->unit('calls'),
        ];
    }
}

/** Implements the contract explicitly, and its own current plan resolution. */
class ContractedUsageBillable extends Model implements ProvidesUsageMetrics
{
    protected $table = 'usage_billables';

    public $timestamps = false;

    protected $guarded = [];

    public array $metrics = [];

    public ?Plan $plan = null;

    public function currentPlan(): ?Plan
    {
        return $this->plan;
    }

    public function meteredUsage(?Plan $plan): array
    {
        return $this->metrics;
    }
}

class NoUsageBillable extends Model
{
    protected $table = 'usage_billables';

    public $timestamps = false;

    protected $guarded = [];
}

class BillingUsageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usage_billables', function (Blueprint $table) {
            $table->increments('id');
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->decimal('monthly_price', 8, 2)->default(0);
            $table->json('features')->nullable();
            $table->boolean('is_free')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function test_usage_is_empty_when_the_billable_does_not_report_it(): void
    {
        $usage = BillingManager::for(NoUsageBillable::create())->usage();

        $this->assertSame([], $usage);
    }

    public function test_a_billable_can_report_usage_without_implementing_the_interface(): void
    {
        $billable = DuckTypedUsageBillable::create();

        $usage = BillingManager::for($billable)->usage();

        $this->assertCount(1, $usage);
        $this->assertInstanceOf(UsageMetricData::class, $usage[0]);
        $this->assertSame('api_calls', $usage[0]->key);
        $this->assertSame('API calls', $usage[0]->label);
        $this->assertSame(1200.0, $usage[0]->used);
    }

    public function test_usage_resolves_the_limit_from_the_billables_current_plan(): void
    {
        $plan = Plan::create([
            'name'     => 'Pro', 'slug' => 'pro', 'monthly_price' => 29,
            'features' => ['usage' => ['api_calls' => 5000]],
        ]);

        $billable          = ContractedUsageBillable::create();
        $billable->plan    = $plan;
        $billable->metrics = [
            UsageMetric::make('api_calls')->used(1000),
        ];

        $usage = BillingManager::for($billable)->usage();

        $this->assertCount(1, $usage);
        $this->assertSame(5000.0, $usage[0]->limit);
        $this->assertSame(20, $usage[0]->percent);
        $this->assertFalse($usage[0]->overLimit);
    }

    public function test_an_explicit_limit_overrides_the_plan_feature(): void
    {
        Plan::create([
            'name'     => 'Pro', 'slug' => 'pro-2', 'monthly_price' => 29,
            'features' => ['usage' => ['seats' => 100]],
        ]);

        $metric = UsageMetric::make('seats')->used(3)->limit(10);
        $data   = UsageMetricData::fromMetric($metric, Plan::where('slug', 'pro-2')->first());

        $this->assertSame(10.0, $data->limit);
        $this->assertSame(30, $data->percent);
    }

    public function test_a_null_plan_feature_means_unlimited_and_percent_is_zero(): void
    {
        $plan = Plan::create(['name' => 'Unlimited', 'slug' => 'unlimited', 'monthly_price' => 99, 'features' => ['usage' => ['projects' => null]]]);

        $data = UsageMetricData::fromMetric(UsageMetric::make('projects')->used(500), $plan);

        $this->assertNull($data->limit);
        $this->assertSame(0, $data->percent);
        $this->assertFalse($data->overLimit);
        $this->assertSame('primary', $data->color);
    }

    public function test_percent_is_capped_at_100_and_over_limit_is_flagged(): void
    {
        $metric = UsageMetric::make('api_calls')->used(150)->limit(100);
        $data   = UsageMetricData::fromMetric($metric, null);

        $this->assertSame(100, $data->percent);
        $this->assertTrue($data->overLimit);
        $this->assertSame('danger', $data->color);
    }

    public function test_default_color_thresholds(): void
    {
        // Comfortable: under 80%.
        $comfortable = UsageMetricData::fromMetric(UsageMetric::make('x')->used(50)->limit(100), null);
        $this->assertSame('primary', $comfortable->color);

        // Nearing the cap: 80–99%.
        $nearing = UsageMetricData::fromMetric(UsageMetric::make('x')->used(85)->limit(100), null);
        $this->assertSame('warning', $nearing->color);

        // At/over the cap.
        $over = UsageMetricData::fromMetric(UsageMetric::make('x')->used(100)->limit(100), null);
        $this->assertSame('danger', $over->color);
    }

    public function test_color_is_fully_customizable_via_a_closure(): void
    {
        $metric = UsageMetric::make('storage')
            ->used(90)
            ->limit(100)
            ->color(fn (float $percent, bool $overLimit): string => $percent > 50 ? 'info' : 'gray');

        $data = UsageMetricData::fromMetric($metric, null);

        // The closure's own logic wins over the built-in thresholds.
        $this->assertSame('info', $data->color);
    }

    public function test_display_formats_used_limit_and_unit(): void
    {
        $withLimit = UsageMetricData::fromMetric(
            UsageMetric::make('api_calls')->used(1234)->limit(5000)->unit('calls'),
            null,
        );
        $this->assertSame('1,234 / 5,000 calls', $withLimit->display);

        $unlimited = UsageMetricData::fromMetric(
            UsageMetric::make('projects')->used(3),
            null,
        );
        $this->assertSame('3', $unlimited->display);
    }

    public function test_report_usage_delegates_to_the_subscription(): void
    {
        $billable      = DuckTypedUsageBillable::create();
        $billable->sub = new UsageFakeSubscription;

        BillingManager::for($billable)->reportUsage(5);
        $this->assertContains('reportUsage:5', $billable->sub->calls);

        BillingManager::for($billable)->reportUsage(3, 'price_metered');
        $this->assertContains('reportUsageFor:price_metered:3', $billable->sub->calls);
    }

    public function test_report_usage_is_a_no_op_without_an_active_subscription(): void
    {
        $billable = DuckTypedUsageBillable::create();

        // Should not throw.
        BillingManager::for($billable)->reportUsage(1);
        $this->assertNull($billable->sub);
    }
}
