<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class KanbanData extends Data
{
    /**
     * @param array<int, KanbanColumnData> $columns
     */
    public function __construct(
        public ?string $heading,
        public array $columns,
        /** Encrypted descriptor {model, statusColumn, statuses} for the move endpoint. */
        public string $model,
    ) {}
}
