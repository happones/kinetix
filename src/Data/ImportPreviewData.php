<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ImportPreviewData extends Data
{
    /**
     * @param array<int, string>                  $headers
     * @param array<int, array<int, string|null>> $rows
     * @param array<int, ImportColumnData>        $columns
     * @param array<string, int|null>             $autoMapping Map of target column name => source header index (or null)
     */
    public function __construct(
        public array $headers,
        public array $rows,
        public array $columns,
        public ImportOptionsData $options,
        public array $autoMapping,
        public string $fileToken,
        public int $totalRows,
    ) {}
}
