<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class NotificationData extends Data
{
    /**
     * @param array<int, ActionData> $actions
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $description,
        public string $status,
        public ?int $duration,
        public ?string $icon,
        public ?string $iconColor,
        public array $actions,
        public string $created_at,
        public ?bool $read = null,
        /** Team PRIMARY key the notification is scoped to; null = global. */
        public int|string|null $team = null,
    ) {}
}
