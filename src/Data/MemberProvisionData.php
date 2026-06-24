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
        public string $email,
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
            $provision->name,
            $provision->role,
            $provision->status->value,
            $provision->isExpired(),
            $provision->activated_at?->toIso8601String(),
            $provision->expires_at?->toIso8601String(),
        );
    }
}
