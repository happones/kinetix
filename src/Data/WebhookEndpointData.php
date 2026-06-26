<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Happones\Kinetix\Webhooks\WebhookEndpoint;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class WebhookEndpointData extends Data
{
    /**
     * @param array<int, string> $events
     */
    public function __construct(
        public int|string|null $id,
        public string $name,
        public string $url,
        public array $events,
        public bool $active,
        public ?string $createdAt,
    ) {}

    public static function fromModel(WebhookEndpoint $endpoint): self
    {
        // Note: the secret is intentionally never serialized here.
        return new self(
            $endpoint->getKey(),
            $endpoint->name,
            $endpoint->url,
            $endpoint->events,
            $endpoint->active,
            $endpoint->created_at?->toIso8601String(),
        );
    }
}
