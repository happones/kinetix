<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Happones\Kinetix\Webhooks\WebhookLog;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class WebhookLogData extends Data
{
    /**
     * @param array<string, mixed>|null $payload
     */
    public function __construct(
        public int|string|null $id,
        public string $event,
        public ?int $statusCode,
        public bool $success,
        public int $attempt,
        public ?string $createdAt,
        public ?array $payload = null,
        public ?string $response = null,
        public ?string $endpointName = null,
        public ?string $endpointUrl = null,
    ) {}

    public static function fromModel(WebhookLog $log): self
    {
        $endpoint = $log->relationLoaded('endpoint') ? $log->getRelation('endpoint') : null;

        return new self(
            $log->getKey(),
            $log->event,
            $log->status_code,
            $log->success,
            $log->attempt,
            $log->created_at?->toIso8601String(),
            $log->payload,
            $log->response,
            $endpoint?->name,
            $endpoint?->url,
        );
    }
}
