<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Billing\BillingRoutes;
use Happones\Kinetix\Billing\Plan;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class HttpBillableSubscription
{
    /** @var array<int, string> */
    public array $calls = [];

    public function __construct(public bool $grace = false) {}

    public string $stripe_status = 'active';

    public ?object $ends_at = null;

    public ?string $stripe_price = 'price_pro_m';

    public function onTrial(): bool
    {
        return false;
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

class HttpSubscriptionBuilder
{
    public function __construct(public HttpBillable $billable, public string $price) {}

    public function trialDays(int $days): self
    {
        return $this;
    }

    public function create(?string $paymentMethod = null): void
    {
        $this->billable->calls[] = "create:{$this->price}:{$paymentMethod}";
    }
}

class HttpBillable extends Authenticatable
{
    protected $table = 'http_billables';

    public $timestamps = false;

    protected $guarded = [];

    public ?string $stripe_id = 'cus_1';

    /** @var array<int, string> */
    public array $calls = [];

    public ?HttpBillableSubscription $sub = null;

    public function onGenericTrial(): bool
    {
        return false;
    }

    public function subscribed(string $type = 'default'): bool
    {
        return $this->sub !== null;
    }

    public function subscription(string $type = 'default'): ?HttpBillableSubscription
    {
        return $this->sub;
    }

    public function newSubscription(string $type, string $price): HttpSubscriptionBuilder
    {
        return new HttpSubscriptionBuilder($this, $price);
    }

    public function paymentMethods(): Collection
    {
        return collect();
    }

    public function defaultPaymentMethod(): ?object
    {
        return null;
    }

    public function hasDefaultPaymentMethod(): bool
    {
        return false;
    }

    public function addPaymentMethod(string $paymentMethod): void
    {
        $this->calls[] = "addPaymentMethod:{$paymentMethod}";
    }

    public function findPaymentMethod(string $id): ?object
    {
        return $id === 'pm_known' ? (object) ['id' => $id] : null;
    }
}

/**
 * The billing routes are the package's money path — subscribe, payment methods,
 * invoices, cancel/resume — and were previously only exercised through the
 * manager, never over HTTP. These cover the endpoint contract: validation,
 * authentication, and that each route reaches the billable it should.
 */
class BillingHttpTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The real middleware stack, unlike the rest of the suite: these routes
        // must not be reachable by a guest.
        $app['config']->set('kinetix.billing.middleware', ['web', 'auth']);
        $app['config']->set('kinetix.billing.enabled', true);
        $app['config']->set('kinetix.billing.view', 'Billing/Index');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('http_billables', function (Blueprint $table) {
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
            $table->boolean('is_free')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Plan::create([
            'name'                    => 'Pro',
            'slug'                    => 'pro',
            'monthly_price'           => 29,
            'stripe_monthly_price_id' => 'price_pro_m',
            'stripe_yearly_price_id'  => 'price_pro_y',
        ]);

        Route::get('login', fn () => 'login')->name('login');

        BillingRoutes::register();
    }

    private function billable(): HttpBillable
    {
        $billable = HttpBillable::create([]);

        // The host names its own billable; the resolver is the documented hook.
        config()->set('kinetix.billing.resolve_billable', fn () => $billable);

        return $billable;
    }

    public function test_a_guest_cannot_reach_any_billing_route(): void
    {
        $this->billable();

        $this->get(route('billing.index'))->assertRedirect();
        $this->post(route('billing.subscribe'), ['plan_slug' => 'pro'])->assertRedirect();
        $this->post(route('billing.payment-methods.add'), ['payment_method' => 'pm_1'])->assertRedirect();
        $this->delete(route('billing.payment-methods.remove', ['id' => 'pm_1']))->assertRedirect();
        $this->post(route('billing.cancel'))->assertRedirect();
        $this->post(route('billing.resume'))->assertRedirect();
    }

    public function test_the_index_renders_the_billing_page(): void
    {
        $billable = $this->billable();

        $response = $this->actingAs($billable)
            ->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Version', '1')
            ->get(route('billing.index'));

        $response->assertOk();
        $response->assertJsonPath('component', 'Billing/Index');
        $response->assertJsonPath('props.plans.0.slug', 'pro');
    }

    public function test_subscribing_requires_a_plan_that_exists(): void
    {
        $billable = $this->billable();

        $this->actingAs($billable)
            ->post(route('billing.subscribe'), ['plan_slug' => 'does-not-exist'])
            ->assertSessionHasErrors('plan_slug');

        $this->assertSame([], $billable->calls);
    }

    public function test_subscribing_rejects_an_unknown_billing_cycle(): void
    {
        $billable = $this->billable();

        $this->actingAs($billable)
            ->post(route('billing.subscribe'), ['plan_slug' => 'pro', 'cycle' => 'fortnightly'])
            ->assertSessionHasErrors('cycle');

        $this->assertSame([], $billable->calls);
    }

    public function test_subscribing_reaches_the_billable_with_the_plans_price(): void
    {
        $billable = $this->billable();

        $this->actingAs($billable)
            ->post(route('billing.subscribe'), [
                'plan_slug'      => 'pro',
                'payment_method' => 'pm_1',
                'cycle'          => 'yearly',
            ])
            ->assertRedirect();

        $this->assertSame(['create:price_pro_y:pm_1'], $billable->calls);
    }

    public function test_adding_a_payment_method_requires_one(): void
    {
        $billable = $this->billable();

        $this->actingAs($billable)
            ->post(route('billing.payment-methods.add'), [])
            ->assertSessionHasErrors('payment_method');

        $this->assertSame([], $billable->calls);
    }

    public function test_adding_a_payment_method_reaches_the_billable(): void
    {
        $billable = $this->billable();

        $this->actingAs($billable)
            ->post(route('billing.payment-methods.add'), ['payment_method' => 'pm_9'])
            ->assertRedirect();

        $this->assertSame(['addPaymentMethod:pm_9'], $billable->calls);
    }

    public function test_removing_an_unknown_payment_method_is_a_no_op(): void
    {
        $billable = $this->billable();

        // findPaymentMethod() returns null for an id the billable doesn't own, so
        // the delete is skipped: one user can't remove another's card by guessing.
        $this->actingAs($billable)
            ->delete(route('billing.payment-methods.remove', ['id' => 'pm_missing']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_cancelling_and_resuming_reach_the_subscription(): void
    {
        $billable      = $this->billable();
        $billable->sub = new HttpBillableSubscription(grace: true);

        $this->actingAs($billable)->post(route('billing.cancel'))->assertRedirect();
        $this->assertContains('cancel', $billable->sub->calls);

        // resume() only applies inside the grace period.
        $this->actingAs($billable)->post(route('billing.resume'))->assertRedirect();
        $this->assertContains('resume', $billable->sub->calls);
    }
}
