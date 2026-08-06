<?php

declare(strict_types=1);

namespace Happones\Kinetix\Billing\Concerns;

use Happones\Kinetix\Billing\Credit;
use Happones\Kinetix\Billing\Exceptions\UsageLimitExceededException;
use Happones\Kinetix\Billing\Plan;
use Happones\Kinetix\Billing\UsageMetric;
use Happones\Kinetix\Billing\UsageRecord;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

/**
 * Metered usage + top-up credits for the billable (pair with {@see HasPlan}).
 * Consumption is tracked per metric key and calendar month in
 * `kinetix_usage`; purchased credits (`kinetix_credits`) extend the plan's
 * `features.usage.*` allowance and are drawn down only past it:
 *
 *     $team->consume('ai_messages');            // throws past allowance+credits
 *     $team->canConsume('ai_messages', 5);      // graceful check
 *     $team->remainingUsage('ai_messages');     // ?int — null = unlimited
 *     $team->addCredits('ai_messages', 1000);   // top-up
 *
 * The trait also ships a default `meteredUsage()` (the
 * `ProvidesUsageMetrics` contract), so `<KinetixUsageMeters>` renders one
 * meter per plan `usage.*` key with REAL tracked numbers — no host wiring.
 * Publish the tables with `--tag=kinetix-billing-migrations`.
 */
trait HasMeteredUsage
{
    public function usageRecords(): MorphMany
    {
        return $this->morphMany(UsageRecord::class, 'billable');
    }

    public function usageCredits(): MorphMany
    {
        return $this->morphMany(Credit::class, 'billable');
    }

    /**
     * The period bucket usage counts against — the calendar month, so
     * counters reset monthly. Override to return `''` for lifetime counters
     * (or any custom bucketing, e.g. a billing-cycle anchor).
     */
    public function usagePeriodKey(): string
    {
        return now()->format('Y-m');
    }

    /**
     * Units consumed for a key in the current period.
     */
    public function currentUsage(string $key): int
    {
        return (int) $this->usageRecords()
            ->where('key', $key)
            ->where('period', $this->usagePeriodKey())
            ->value('used');
    }

    /**
     * The remaining top-up credit balance for a key.
     */
    public function creditsFor(string $key): int
    {
        return (int) $this->usageCredits()->where('key', $key)->value('balance');
    }

    /**
     * Add top-up credits for a key (e.g. after a one-off purchase).
     */
    public function addCredits(string $key, int $amount): void
    {
        DB::transaction(function () use ($key, $amount): void {
            $credit = $this->usageCredits()
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            $credit === null
                ? $this->usageCredits()->create(['key' => $key, 'balance' => $amount])
                : $credit->increment('balance', $amount);
        });
    }

    /**
     * Units still available for a key: plan allowance minus used, plus
     * credits — floored at zero. Null means unlimited (no `usage.*` value on
     * the plan, or no plan at all): metering never blocks an un-limited app.
     */
    public function remainingUsage(string $key): ?int
    {
        $allowance = $this->usageAllowance($key);

        if ($allowance === null) {
            return null;
        }

        return max(0, $allowance - $this->currentUsage($key)) + $this->creditsFor($key);
    }

    public function canConsume(string $key, int $amount = 1): bool
    {
        $remaining = $this->remainingUsage($key);

        return $remaining === null || $amount <= $remaining;
    }

    /**
     * Record a consumption, atomically: the counter row and the credit row
     * are locked, the plan allowance is spent first, and only the excess
     * draws credits down. Past `allowance + credits` a
     * {@see UsageLimitExceededException} (403) aborts with nothing recorded.
     */
    public function consume(string $key, int $amount = 1): void
    {
        DB::transaction(function () use ($key, $amount): void {
            $record = $this->usageRecords()
                ->where('key', $key)
                ->where('period', $this->usagePeriodKey())
                ->lockForUpdate()
                ->first();

            $record ??= $this->usageRecords()->create([
                'key'    => $key,
                'period' => $this->usagePeriodKey(),
                'used'   => 0,
            ]);

            $allowance = $this->usageAllowance($key);

            if ($allowance !== null) {
                $credit  = $this->usageCredits()->where('key', $key)->lockForUpdate()->first();
                $balance = (int) ($credit?->balance ?? 0);

                $fromAllowance = max(0, $allowance - $record->used);
                $available     = $fromAllowance + $balance;

                if ($amount > $available) {
                    throw new UsageLimitExceededException($key, $available, $allowance + $balance);
                }

                $fromCredits = max(0, $amount - $fromAllowance);

                if ($fromCredits > 0) {
                    $credit?->decrement('balance', $fromCredits);
                }
            }

            $record->increment('used', $amount);
        });
    }

    /**
     * Default `ProvidesUsageMetrics` implementation: one metric per plan
     * `usage.*` key with the REAL tracked count. With credits on a key, the
     * meter's limit becomes `allowance + credits` so the purchased headroom
     * shows; without them, `BillingManager::usage()` falls back to the plan
     * limit as usual. Override for custom labels/units or computed metrics.
     *
     * @return array<int, UsageMetric>
     */
    public function meteredUsage(?Plan $plan): array
    {
        $keys = array_keys((array) data_get($plan?->features, 'usage', []));

        return array_map(function (string $key): UsageMetric {
            $metric = UsageMetric::make($key)->used($this->currentUsage($key));

            $allowance = $this->usageAllowance($key);
            $credits   = $this->creditsFor($key);

            if ($allowance !== null && $credits > 0) {
                $metric->limit($allowance + $credits);
            }

            return $metric;
        }, $keys);
    }

    /**
     * The plan's allowance for a key (`features.usage.*`), or null when
     * unlimited — resolved through {@see HasPlan::planLimit()} when present.
     */
    protected function usageAllowance(string $key): ?int
    {
        return method_exists($this, 'planLimit') ? $this->planLimit($key) : null;
    }
}
