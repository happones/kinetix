<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ImportOptionsData extends Data
{
    public function __construct(
        public string $delimiter = ',',
        public string $enclosure = '"',
        public int $skipLines = 0,
        public bool $hasHeader = true,
    ) {}
}
