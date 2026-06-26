<?php

declare(strict_types=1);

namespace Happones\Kinetix\Onboarding;

/**
 * Static entry point for the onboarding checklist. Declare steps in a provider:
 *
 *     KinetixOnboarding::step('verify-email', 'Verify your email')
 *         ->cta('Resend', '/email/verify')
 *         ->completedUsing(fn ($user) => $user->hasVerifiedEmail());
 */
class KinetixOnboarding
{
    public static function registry(): OnboardingStepRegistry
    {
        return app(OnboardingStepRegistry::class);
    }

    public static function step(string $key, string $title): OnboardingStep
    {
        return static::registry()->add(OnboardingStep::make($key, $title));
    }
}
