<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class KanbanColumnData extends Data
{
    /**
     * @param array<int, KanbanCardData> $cards
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $color,
        public array $cards,
    ) {}
}
