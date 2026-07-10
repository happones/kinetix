<?php

declare(strict_types=1);

namespace Happones\Kinetix\Billing;

use Closure;
use Happones\Kinetix\Data\UsageMetricData;

/**
 * One metered usage dimension (API calls, seats, storage, …), built inside
 * {@see Contracts\ProvidesUsageMetrics::meteredUsage()} and resolved by
 * {@see BillingManager::usage()} into a {@see UsageMetricData}
 * for the `<KinetixUsageMeters>` progress display.
 *
 *     UsageMetric::make('api_calls')
 *         ->label('API calls')
 *         ->used($this->apiCallsThisPeriod())
 *         ->unit('calls');
 *
 *     // Explicit limit (skips the plan's `features.usage.seats` lookup):
 *     UsageMetric::make('seats')->used($this->members()->count())->limit(10);
 *
 *     // Custom color logic instead of the default thresholds:
 *     UsageMetric::make('storage')->used($gb)->unit('GB')
 *         ->color(fn (float $percent, bool $overLimit) => $overLimit ? 'danger' : 'info');
 */
class UsageMetric
{
    protected ?string $label = null;

    protected float $used = 0.0;

    protected ?float $limit = null;

    protected bool $limitExplicit = false;

    protected ?string $unit = null;

    protected string|Closure|null $color = null;

    final protected function __construct(public readonly string $key) {}

    public static function make(string $key): static
    {
        return new static($key);
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function used(int|float $used): static
    {
        $this->used = (float) $used;

        return $this;
    }

    /**
     * Override the included allowance for this metric. When never called,
     * {@see BillingManager::usage()} falls back to the current plan's
     * `features.usage.{key}` (`null` there means unlimited). Pass `null`
     * explicitly to force "unlimited" regardless of the plan.
     */
    public function limit(int|float|null $limit): static
    {
        $this->limit         = $limit !== null ? (float) $limit : null;
        $this->limitExplicit = true;

        return $this;
    }

    /**
     * Suffix shown after the numbers, e.g. "GB", "calls", "seats".
     */
    public function unit(string $unit): static
    {
        $this->unit = $unit;

        return $this;
    }

    /**
     * Override the automatic color thresholds (<80% primary, 80–99% warning,
     * ≥100% danger). A closure receives `(float $percent, bool $overLimit)`.
     *
     * @param string|Closure(float, bool): string $color
     */
    public function color(string|Closure $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getUsed(): float
    {
        return $this->used;
    }

    public function getLimit(): ?float
    {
        return $this->limit;
    }

    public function hasExplicitLimit(): bool
    {
        return $this->limitExplicit;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function getColor(): string|Closure|null
    {
        return $this->color;
    }
}
