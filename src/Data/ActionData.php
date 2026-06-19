<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ActionData extends Data
{
    /**
     * @param array<string, mixed> $dispatchData
     * @param array<string, mixed>|null $inertiaVisit
     */
    public function __construct(
        public string $name,
        public string $label,
        public ?string $icon = null,
        public string $iconPosition = 'before',
        public ?string $url = null,
        public bool $shouldOpenInNewTab = false,
        public string $color = 'primary',
        public string $size = 'sm',
        public string $viewType = 'button',
        public bool $shouldClose = false,
        public bool $shouldMarkAsRead = false,
        public bool $shouldMarkAsUnread = false,
        public ?string $dispatchEvent = null,
        public array $dispatchData = [],
        public ?array $inertiaVisit = null,
    ) {}
}
