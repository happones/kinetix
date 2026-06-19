<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class TableStateData extends Data
{
    /**
     * @param array<string, mixed> $filters
     */
    public function __construct(
        public string $search,
        public string $sort,
        public string $direction,
        public array $filters,
        public int $perPage,
    ) {}
}
