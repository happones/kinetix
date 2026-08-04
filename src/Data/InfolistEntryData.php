<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class InfolistEntryData extends Data
{
    /**
     * @param array<string, mixed>|int|string|null $columnSpan
     * @param array<int, InfolistEntryData>|null   $schema
     * @param array<string, string>|null           $extraAttributes
     */
    public function __construct(
        public string $type,
        public ?string $name = null,
        public ?string $label = null,
        public mixed $columnSpan = 'full',
        public mixed $state = null,
        public ?string $placeholder = null,
        public ?string $icon = null,
        public ?string $color = null,
        public ?string $url = null,
        public bool $openUrlInNewTab = false,
        public ?bool $isBadge = null,
        public ?bool $isCopyable = null,
        public ?bool $isCircular = null,
        public int|string|null $size = null,
        public bool $isInline = false,
        // UI-only affordance flag — actual masking is enforced by
        // ConfidentialCast regardless of whether this flag is set.
        public ?bool $isConfidential = null,
        public ?array $extraAttributes = null,
        // Layout components specific
        public ?array $schema = null,
        public ?string $heading = null,
        public ?string $description = null,
        /** @var int|array<string, int>|null */
        public int|array|null $columns = null,
        // Section header actions (array of ActionData).
        public ?array $actions = null,
    ) {}
}
