<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Happones\Kinetix\Billing\Plan;
use Happones\Kinetix\Billing\UsageMetric;
use NumberFormatter;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class UsageMetricData extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public float $used,
        public ?float $limit,
        public int $percent,
        public string $display,
        public ?string $unit,
        public string $color,
        public bool $overLimit,
    ) {}

    /**
     * Resolve a declared {@see UsageMetric} against the billable's current
     * plan: an explicit `limit()` wins, otherwise it falls back to the
     * plan's `features.usage.{key}` (`null` = unlimited either way).
     */
    public static function fromMetric(UsageMetric $metric, ?Plan $plan): self
    {
        $limit = $metric->hasExplicitLimit()
            ? $metric->getLimit()
            : self::planLimit($metric->key, $plan);

        $used    = $metric->getUsed();
        $percent = $limit !== null && $limit > 0
            ? (int) round(min(100, max(0, ($used / $limit) * 100)))
            : 0;
        $overLimit = $limit !== null && $used >= $limit;

        return new self(
            key: $metric->key,
            label: $metric->getLabel() ?? (string) str($metric->key)->headline(),
            used: $used,
            limit: $limit,
            percent: $percent,
            display: self::display($used, $limit, $metric->getUnit()),
            unit: $metric->getUnit(),
            color: self::resolveColor($metric, $percent, $overLimit),
            overLimit: $overLimit,
        );
    }

    protected static function planLimit(string $key, ?Plan $plan): ?float
    {
        if ($plan === null) {
            return null;
        }

        $value = $plan->featureValue("usage.{$key}");

        return $value !== null ? (float) $value : null;
    }

    protected static function resolveColor(UsageMetric $metric, int $percent, bool $overLimit): string
    {
        $color = $metric->getColor();

        if ($color instanceof \Closure) {
            return (string) $color((float) $percent, $overLimit);
        }

        if ($color !== null) {
            return $color;
        }

        // Default thresholds: comfortable · nearing the cap · at/over it.
        if ($overLimit) {
            return 'danger';
        }

        if ($percent >= 80) {
            return 'warning';
        }

        return 'primary';
    }

    protected static function display(float $used, ?float $limit, ?string $unit): string
    {
        $formatted = self::formatNumber($used);

        if ($limit !== null) {
            $formatted .= ' / '.self::formatNumber($limit);
        }

        return $unit !== null ? "{$formatted} {$unit}" : $formatted;
    }

    protected static function formatNumber(float $value): string
    {
        $decimals = floor($value) == $value ? 0 : 2;

        if (class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter(app()->getLocale(), NumberFormatter::DECIMAL);
            $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $decimals);

            return (string) $formatter->format($value);
        }

        return number_format($value, $decimals);
    }
}
