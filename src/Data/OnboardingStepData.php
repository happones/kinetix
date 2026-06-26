<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Happones\Kinetix\Onboarding\OnboardingStep;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class OnboardingStepData extends Data
{
    public function __construct(
        public string $key,
        public string $title,
        public ?string $description,
        public ?string $ctaLabel,
        public ?string $ctaHref,
        public ?string $icon,
        public bool $completed,
        public bool $manual,
    ) {}

    public static function fromStep(OnboardingStep $step, bool $completed): self
    {
        return new self(
            $step->key,
            $step->title,
            $step->getDescription(),
            $step->getCtaLabel(),
            $step->getCtaHref(),
            $step->getIcon(),
            $completed,
            $step->isManual(),
        );
    }
}
