<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Happones\Kinetix\Channels\Channel;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ChannelData extends Data
{
    public function __construct(
        public int|string $id,
        public string $provider,
        public string $name,
        public string $status,
        public ?string $handle,
        public ?string $lastError,
        public ?string $connectedAt,
        public ?string $lastEventAt,
        public ?string $createdAt,
    ) {}

    public static function fromModel(Channel $channel): self
    {
        // Credentials and the webhook secret never leave the server.
        return new self(
            $channel->getKey(),
            $channel->provider,
            $channel->name,
            $channel->status->value,
            $channel->handle,
            $channel->last_error,
            $channel->connected_at?->toIso8601String(),
            $channel->last_event_at?->toIso8601String(),
            $channel->created_at?->toIso8601String(),
        );
    }
}
