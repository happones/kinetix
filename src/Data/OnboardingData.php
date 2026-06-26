<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class OnboardingData extends Data
{
    /**
     * @param array<int, OnboardingStepData> $steps
     */
    public function __construct(
        public array $steps,
        public int $completedCount,
        public int $total,
        public bool $complete,
        public bool $dismissed,
    ) {}
}
