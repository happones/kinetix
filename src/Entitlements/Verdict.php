<?php

declare(strict_types=1);

namespace Happones\Kinetix\Entitlements;

use Happones\Kinetix\Billing\UpsellResponse;
use Illuminate\Contracts\Support\Arrayable;
use Symfony\Component\HttpFoundation\Response;

/**
 * The outcome of evaluating one entitlement: whether it is allowed, and — when
 * it is not — WHICH layer said no, so the caller can answer accordingly
 * (hide it, sell it, or refuse it).
 *
 * @implements Arrayable<string, mixed>
 */
final class Verdict implements Arrayable
{
    public function __construct(
        public readonly string $entitlement,
        public readonly bool $allowed,
        public readonly ?DenialReason $reason = null,
        /** Units left on the entitlement's usage limit; null = unlimited or no limit declared. */
        public readonly ?int $remaining = null,
    ) {}

    public static function allow(string $entitlement, ?int $remaining = null): self
    {
        return new self($entitlement, true, null, $remaining);
    }

    public static function deny(string $entitlement, DenialReason $reason, ?int $remaining = null): self
    {
        return new self($entitlement, false, $reason, $remaining);
    }

    public function denied(): bool
    {
        return ! $this->allowed;
    }

    /**
     * Whether this denial should be answered with an upgrade CTA rather than a
     * refusal.
     */
    public function isUpsell(): bool
    {
        return $this->denied() && $this->reason?->isUpsell() === true;
    }

    public function status(): int
    {
        return $this->reason?->status() ?? 200;
    }

    public function message(): string
    {
        return (string) __($this->reason?->messageKey() ?? 'kinetix.entitlement_forbidden');
    }

    /**
     * Stop the request unless this verdict allows it.
     *
     * A plan/limit denial on a web request takes the upsell path (a redirect
     * to the configured upgrade page); everything else aborts with the
     * reason's status. Returns null when allowed, so a middleware can simply
     * `return $this->verdict->enforce() ?? $next($request);`.
     */
    public function enforce(): ?Response
    {
        if ($this->allowed) {
            return null;
        }

        if ($this->isUpsell()) {
            return UpsellResponse::make($this->message());
        }

        abort($this->status(), $this->message());
    }

    /**
     * @return array{allowed: bool, reason: ?string, remaining: ?int}
     */
    public function toArray(): array
    {
        return [
            'allowed'   => $this->allowed,
            'reason'    => $this->reason?->value,
            'remaining' => $this->remaining,
        ];
    }
}
