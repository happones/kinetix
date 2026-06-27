<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Happones\Kinetix\ConnectedAccounts\ConnectedAccount;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ConnectedAccountData extends Data
{
    public function __construct(
        public int|string|null $id,
        public string $provider,
        public ?string $name,
        public ?string $nickname,
        public ?string $email,
        public ?string $avatar,
        public ?string $createdAt,
    ) {}

    public static function fromModel(ConnectedAccount $account): self
    {
        $createdAt = $account->created_at;

        return new self(
            $account->getKey(),
            $account->provider,
            $account->name,
            $account->nickname,
            $account->email,
            $account->avatar,
            $createdAt instanceof \DateTimeInterface ? $createdAt->format(\DateTimeInterface::ATOM) : null,
        );
    }
}
