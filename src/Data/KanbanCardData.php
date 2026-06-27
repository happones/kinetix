<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class KanbanCardData extends Data
{
    public function __construct(
        public int|string $id,
        public string $title,
        public ?string $description = null,
    ) {}
}
