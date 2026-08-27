<?php

declare(strict_types=1);

namespace Happones\Kinetix\Membership;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;

/**
 * What an admin has to hand over when there is no delivery channel — shown
 * EXACTLY ONCE, the same contract as a Sanctum personal access token.
 *
 * Either a temporary password (`direct` provisioning) or a signed activation
 * link (`activation` provisioning with `manual` delivery). Neither is stored in
 * a readable form: the password is hashed on the way into the user record, and
 * the link's validity is its signature. There is no "show it again" — only
 * issuing a new one, which is deliberate.
 *
 * @implements Arrayable<string, mixed>
 */
final class MemberCredential implements Arrayable
{
    private function __construct(
        /** `password` or `link` — what the admin is looking at. */
        public readonly string $type,
        /** The secret itself. Present only in the response that created it. */
        public readonly string $value,
        public readonly ?Carbon $expiresAt = null,
    ) {}

    public static function password(string $plain, ?Carbon $expiresAt = null): self
    {
        return new self('password', $plain, $expiresAt);
    }

    public static function link(string $url, ?Carbon $expiresAt = null): self
    {
        return new self('link', $url, $expiresAt);
    }

    /**
     * @return array{type: string, value: string, expiresAt: ?string}
     */
    public function toArray(): array
    {
        return [
            'type'      => $this->type,
            'value'     => $this->value,
            'expiresAt' => $this->expiresAt?->toIso8601String(),
        ];
    }

    /**
     * Never let a credential end up in a log line, an exception trace or a
     * `dd()` by accident.
     */
    public function __toString(): string
    {
        return '['.$this->type.' redacted]';
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['type' => $this->type, 'value' => '[redacted]'];
    }
}
