<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Billing\Concerns\HasPlan;
use Happones\Kinetix\Billing\Plan;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class SharedPlanUser extends Authenticatable
{
    use HasPlan;

    protected $table = 'shared_plan_users';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * The `kinetix_billing` Inertia share hands the SPA the billable's current
 * plan (slug/name + features JSON) so useKinetixPlan() / <KinetixPlanFeature>
 * can gate menus and buttons on the same dot-paths the server enforces.
 */
class BillingSharedPlanTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.billing.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('shared_plan_users', function (Blueprint $table) {
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
    }

    /**
     * @return array{enabled: bool, plan: ?array{slug: string, name: string, features: array<string, mixed>}}
     */
    private function sharedBilling(): array
    {
        /** @var callable $shared */
        $shared = Inertia::getShared('kinetix_billing');

        return value($shared);
    }

    public function test_shares_the_resolved_plan_with_its_features(): void
    {
        Plan::create([
            'name'          => 'Free',
            'monthly_price' => 0,
            'is_free'       => true,
            'features'      => [
                'usage'        => ['products' => 10],
                'capabilities' => ['api' => false],
            ],
        ]);

        $this->actingAs(SharedPlanUser::create(['name' => 'Jane']));

        $data = $this->sharedBilling();

        $this->assertTrue($data['enabled']);
        $this->assertSame('free', $data['plan']['slug']);
        $this->assertSame('Free', $data['plan']['name']);
        $this->assertSame(10, $data['plan']['features']['usage']['products']);
        $this->assertFalse($data['plan']['features']['capabilities']['api']);
    }

    public function test_shares_a_null_plan_when_none_resolves(): void
    {
        // No plans at all — the share must degrade to null, never throw.
        $this->actingAs(SharedPlanUser::create(['name' => 'Jane']));

        $data = $this->sharedBilling();

        $this->assertTrue($data['enabled']);
        $this->assertNull($data['plan']);
    }

    public function test_share_is_disabled_for_guests(): void
    {
        $data = $this->sharedBilling();

        $this->assertFalse($data['enabled']);
        $this->assertNull($data['plan']);
    }

    public function test_share_is_disabled_when_billing_is_off(): void
    {
        config(['kinetix.billing.enabled' => false]);

        $this->actingAs(SharedPlanUser::create(['name' => 'Jane']));

        $data = $this->sharedBilling();

        $this->assertFalse($data['enabled']);
        $this->assertNull($data['plan']);
    }
}
