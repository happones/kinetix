<?php

declare(strict_types=1);

namespace Happones\Kinetix\Onboarding;

/**
 * The ordered catalog of first-run checklist steps, declared from a service
 * provider. Re-registering the same key replaces the earlier definition.
 */
class OnboardingStepRegistry
{
    /**
     * @var array<string, OnboardingStep>
     */
    protected array $steps = [];

    public function add(OnboardingStep $step): OnboardingStep
    {
        $this->steps[$step->key] = $step;

        return $step;
    }

    /**
     * @return array<int, OnboardingStep>
     */
    public function all(): array
    {
        return array_values($this->steps);
    }

    public function get(string $key): ?OnboardingStep
    {
        return $this->steps[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->steps[$key]);
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->steps);
    }
}
