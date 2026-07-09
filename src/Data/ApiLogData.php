<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Happones\Kinetix\Api\ApiLog;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ApiLogData extends Data
{
    /**
     * @param array<string, mixed>|null $requestBody
     */
    public function __construct(
        public int|string|null $id,
        public string $method,
        public string $path,
        public int $status,
        public ?int $durationMs,
        public ?string $tokenName,
        public ?string $ip,
        public ?array $requestBody,
        public ?string $responseBody,
        public ?string $createdAt,
    ) {}

    public static function fromModel(ApiLog $log): self
    {
        return new self(
            $log->getKey(),
            $log->method,
            $log->path,
            $log->status,
            $log->duration_ms,
            $log->token_name,
            $log->ip,
            $log->request_body,
            $log->response_body,
            $log->created_at?->toIso8601String(),
        );
    }
}
