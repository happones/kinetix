<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Carbon\Carbon;
use Happones\Kinetix\Billing\BillingManager;
use Happones\Kinetix\Billing\Concerns\HasMeteredUsage;
use Happones\Kinetix\Billing\Concerns\HasPlan;
use Happones\Kinetix\Billing\Exceptions\UsageLimitExceededException;
use Happones\Kinetix\Billing\Plan;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;

class MuUser extends Authenticatable
{
    use HasMeteredUsage;
    use HasPlan;

    protected $table = 'mu_users';

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

class MeteredUsageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('mu_users', function (Blueprint $table) {
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

        Schema::create('kinetix_usage', function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->string('key');
            $table->string('period', 7)->default('');
            $table->unsignedBigInteger('used')->default(0);
            $table->timestamps();
            $table->unique(['billable_type', 'billable_id', 'key', 'period'], 'kinetix_usage_unique');
        });

        Schema::create('kinetix_credits', function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->string('key');
            $table->unsignedBigInteger('balance')->default(0);
            $table->timestamps();
            $table->unique(['billable_type', 'billable_id', 'key'], 'kinetix_credits_unique');
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function planWith(array $usage): Plan
    {
        return Plan::create(['name' => 'Free', 'is_free' => true, 'features' => ['usage' => $usage]]);
    }

    public function test_consume_tracks_within_the_allowance_and_throws_past_it(): void
    {
        $this->planWith(['ai_messages' => 3]);
        $user = MuUser::create(['name' => 'Jane']);

        $user->consume('ai_messages');
        $user->consume('ai_messages', 2);

        $this->assertSame(3, $user->currentUsage('ai_messages'));
        $this->assertSame(0, $user->remainingUsage('ai_messages'));
        $this->assertFalse($user->canConsume('ai_messages'));

        try {
            $user->consume('ai_messages');
            $this->fail('Expected UsageLimitExceededException.');
        } catch (UsageLimitExceededException $e) {
            $this->assertSame('ai_messages', $e->key);
            $this->assertSame(0, $e->remaining);
            $this->assertSame(403, $e->getStatusCode());
        }

        // Nothing was recorded by the failed consumption.
        $this->assertSame(3, $user->currentUsage('ai_messages'));
    }

    public function test_credits_extend_the_allowance_and_only_the_excess_draws_them_down(): void
    {
        $this->planWith(['ai_messages' => 5]);
        $user = MuUser::create(['name' => 'Jane']);
        $user->addCredits('ai_messages', 3);

        $this->assertSame(8, $user->remainingUsage('ai_messages'));

        // 7 = 5 from the allowance + 2 from credits.
        $user->consume('ai_messages', 7);

        $this->assertSame(7, $user->currentUsage('ai_messages'));
        $this->assertSame(1, $user->creditsFor('ai_messages'));
        $this->assertSame(1, $user->remainingUsage('ai_messages'));

        // The last credit, then a hard stop.
        $user->consume('ai_messages');
        $this->assertSame(0, $user->creditsFor('ai_messages'));
        $this->assertFalse($user->canConsume('ai_messages'));
    }

    public function test_an_unlimited_key_never_blocks_and_never_touches_credits(): void
    {
        $this->planWith(['other' => 5]); // no ai_messages entry = unlimited
        $user = MuUser::create(['name' => 'Jane']);
        $user->addCredits('ai_messages', 3);

        $user->consume('ai_messages', 500);

        $this->assertSame(500, $user->currentUsage('ai_messages'));
        $this->assertSame(3, $user->creditsFor('ai_messages'));
        $this->assertNull($user->remainingUsage('ai_messages'));
        $this->assertTrue($user->canConsume('ai_messages', PHP_INT_MAX));
    }

    public function test_usage_resets_with_the_calendar_month_but_credits_persist(): void
    {
        $this->planWith(['ai_messages' => 5]);
        $user = MuUser::create(['name' => 'Jane']);
        $user->addCredits('ai_messages', 2);

        Carbon::setTestNow('2026-08-15 10:00:00');
        $user->consume('ai_messages', 6); // 5 allowance + 1 credit

        $this->assertSame(6, $user->currentUsage('ai_messages'));
        $this->assertSame(1, $user->creditsFor('ai_messages'));

        // New month: the counter starts fresh; the remaining credit carried over.
        Carbon::setTestNow('2026-09-01 00:05:00');
        $this->assertSame(0, $user->currentUsage('ai_messages'));
        $this->assertSame(6, $user->remainingUsage('ai_messages')); // 5 + 1 credit
    }

    public function test_metered_usage_feeds_the_usage_meters_with_tracked_numbers(): void
    {
        config()->set('kinetix.billing.enabled', true);
        config()->set('kinetix.billing.billable', MuUser::class);

        $this->planWith(['ai_messages' => 10, 'exports' => 5]);
        $user = MuUser::create(['name' => 'Jane']);
        $user->consume('ai_messages', 4);
        $user->addCredits('ai_messages', 5);

        $metrics = collect(BillingManager::for($user)->usage())->keyBy('key');

        // Tracked count + credit headroom on the limit.
        $this->assertSame(4.0, $metrics['ai_messages']->used);
        $this->assertSame(15.0, $metrics['ai_messages']->limit);

        // No credits: the plan limit rules via the standard fallback.
        $this->assertSame(0.0, $metrics['exports']->used);
        $this->assertSame(5.0, $metrics['exports']->limit);
    }
}
