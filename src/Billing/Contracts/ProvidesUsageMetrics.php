<?php

declare(strict_types=1);

namespace Happones\Kinetix\Billing\Contracts;

use Happones\Kinetix\Billing\Plan;
use Happones\Kinetix\Billing\UsageMetric;

/**
 * A billable (User/Team/…) that can report its own metered usage — how much
 * of each usage-based dimension (API calls, seats, storage, …) it has
 * consumed this period. Implement it to power `BillingManager::usage()` and
 * the `<KinetixUsageMeters>` progress display:
 *
 *     class Team extends Model implements ProvidesUsageMetrics
 *     {
 *         public function meteredUsage(?Plan $plan): array
 *         {
 *             return [
 *                 UsageMetric::make('api_calls')
 *                     ->label('API calls')
 *                     ->used($this->apiCallsThisPeriod()),
 *                 // 'limit' is omitted here — BillingManager falls back to
 *                 // the current plan's `features.usage.api_calls`.
 *             ];
 *         }
 *     }
 *
 * The interface is optional (hybrid detection): any billable exposing a
 * `meteredUsage(?Plan $plan): array` method is accepted the same way, so
 * existing models don't need to add a `use` statement to opt in.
 */
interface ProvidesUsageMetrics
{
    /**
     * @return array<int, UsageMetric>
     */
    public function meteredUsage(?Plan $plan): array;
}
