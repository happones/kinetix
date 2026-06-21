<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class RelationManagerData extends Data
{
    public function __construct(
        public string $title,
        public string $relationship,
        public TableData $table,
    ) {}
}
