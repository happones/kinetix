<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Happones\Kinetix\Webhooks\WebhookLog;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class WebhookLogData extends Data
{
    public function __construct(
        public int|string|null $id,
        public string $event,
        public ?int $statusCode,
        public bool $success,
        public int $attempt,
        public ?string $createdAt,
    ) {}

    public static function fromModel(WebhookLog $log): self
    {
        return new self(
            $log->getKey(),
            $log->event,
            $log->status_code,
            $log->success,
            $log->attempt,
            $log->created_at?->toIso8601String(),
        );
    }
}
