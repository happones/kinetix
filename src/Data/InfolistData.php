<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class InfolistData extends Data
{
    /**
     * @param array<int, InfolistEntryData> $schema
     */
    public function __construct(
        public array $schema,
        public int $columns = 1,
        public string $operation = 'view',
    ) {}
}
