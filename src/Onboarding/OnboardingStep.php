<?php

declare(strict_types=1);

namespace Happones\Kinetix\Onboarding;

use Closure;

/**
 * A single first-run checklist step. Built fluently:
 *
 *     OnboardingStep::make('verify-email', 'Verify your email')
 *         ->description('Confirm your address to unlock everything.')
 *         ->cta('Resend email', '/email/verify')
 *         ->icon('mail')
 *         ->completedUsing(fn ($user) => $user->hasVerifiedEmail());
 */
class OnboardingStep
{
    protected ?string $description = null;

    protected ?string $ctaLabel = null;

    protected ?string $ctaHref = null;

    protected ?string $icon = null;

    /**
     * @var (Closure(mixed): bool)|null
     */
    protected ?Closure $completedUsing = null;

    public function __construct(
        public readonly string $key,
        public readonly string $title,
    ) {}

    public static function make(string $key, string $title): self
    {
        return new self($key, $title);
    }

    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function cta(string $label, string $href): static
    {
        $this->ctaLabel = $label;
        $this->ctaHref  = $href;

        return $this;
    }

    public function icon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * Provide a callback that auto-detects completion from app state. It
     * receives the authenticated user and returns whether the step is done.
     *
     * @param Closure(mixed): bool $callback
     */
    public function completedUsing(Closure $callback): static
    {
        $this->completedUsing = $callback;

        return $this;
    }

    /**
     * Resolve whether this step is auto-completed for the given user. Steps with
     * no callback are manual (completion comes from persisted state instead).
     */
    public function isAutoCompleted(mixed $user): bool
    {
        if ($this->completedUsing === null) {
            return false;
        }

        return (bool) ($this->completedUsing)($user);
    }

    public function isManual(): bool
    {
        return $this->completedUsing === null;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getCtaLabel(): ?string
    {
        return $this->ctaLabel;
    }

    public function getCtaHref(): ?string
    {
        return $this->ctaHref;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }
}
