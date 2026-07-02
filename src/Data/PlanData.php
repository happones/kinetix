<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Happones\Kinetix\Billing\Plan;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class PlanData extends Data
{
    /**
     * @param array<string, mixed> $features
     * @param array<int, string>   $highlightedFeatures
     */
    public function __construct(
        public int|string|null $id,
        public string $name,
        public string $slug,
        public ?string $description,
        public ?float $monthlyPrice,
        public ?float $yearlyPrice,
        public array $features,
        public array $highlightedFeatures,
        public bool $isFeatured,
        public bool $isFree,
        public int $sortOrder,
        public ?int $trialDays,
    ) {}

    public static function fromPlan(Plan $plan): self
    {
        return new self(
            id: $plan->getKey(),
            name: (string) $plan->name,
            slug: (string) $plan->slug,
            description: $plan->description,
            monthlyPrice: $plan->priceFor('monthly'),
            yearlyPrice: $plan->priceFor('yearly'),
            features: (array) ($plan->features ?? []),
            highlightedFeatures: (array) ($plan->highlighted_features ?? []),
            isFeatured: (bool) $plan->is_featured,
            isFree: $plan->isFree(),
            sortOrder: (int) $plan->sort_order,
            trialDays: $plan->trial_days !== null ? (int) $plan->trial_days : null,
        );
    }
}
