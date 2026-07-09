<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class TokenData extends Data
{
    /**
     * @param array<int, string> $abilities
     */
    public function __construct(
        public int|string|null $id,
        public string $name,
        public array $abilities,
        public ?string $lastUsedAt,
        public ?string $createdAt,
        public ?string $expiresAt = null,
    ) {}

    /**
     * Build from a Sanctum PersonalAccessToken (typed loosely so the package
     * never hard-depends on laravel/sanctum). The plaintext token is never
     * serialized here — it is returned exactly once on creation.
     */
    public static function fromModel(Model $token): self
    {
        $abilities  = $token->getAttribute('abilities');
        $lastUsedAt = $token->getAttribute('last_used_at');
        $createdAt  = $token->getAttribute('created_at');
        $expiresAt  = $token->getAttribute('expires_at');

        return new self(
            $token->getKey(),
            (string) $token->getAttribute('name'),
            is_array($abilities) ? array_values($abilities) : [],
            $lastUsedAt instanceof \DateTimeInterface ? $lastUsedAt->format(\DateTimeInterface::ATOM) : null,
            $createdAt instanceof \DateTimeInterface ? $createdAt->format(\DateTimeInterface::ATOM) : null,
            $expiresAt instanceof \DateTimeInterface ? $expiresAt->format(\DateTimeInterface::ATOM) : null,
        );
    }
}
