<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Happones\Kinetix\Membership\MemberProvision;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class MemberProvisionData extends Data
{
    public function __construct(
        public int|string|null $id,
        /** Null for a member provisioned by username or phone instead. */
        public ?string $email,
        public ?string $username,
        public ?string $phone,
        /** Whichever of the three identifies this member — for display. */
        public ?string $identifier,
        public ?string $name,
        public string $role,
        public string $status,
        public bool $expired,
        public ?string $activatedAt,
        public ?string $expiresAt,
    ) {}

    public static function fromModel(MemberProvision $provision): self
    {
        return new self(
            $provision->getKey(),
            $provision->email,
            $provision->username,
            $provision->phone,
            $provision->identifier(),
            $provision->name,
            $provision->role,
            $provision->status->value,
            $provision->isExpired(),
            $provision->activated_at?->toIso8601String(),
            $provision->expires_at?->toIso8601String(),
        );
    }
}
