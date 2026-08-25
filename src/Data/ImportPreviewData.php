<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ImportPreviewData extends Data
{
    /**
     * @param array<int, string>                  $headers      The file's source columns
     * @param array<int, array<int, string|null>> $rows         Sample rows only — capped at $settings->previewRows
     * @param array<int, ImportColumnData>        $columns      The importer's target columns
     * @param array<string, int|null>             $autoMapping  Map of target column name => source header index (or null)
     * @param bool                                $isExactMatch Every column matched a header and every header was claimed
     * @param int                                 $totalRows    Data rows in the file (a cheap count, not a parse)
     */
    public function __construct(
        public array $headers,
        public array $rows,
        public array $columns,
        public ImportOptionsData $options,
        public ImportSettingsData $settings,
        public array $autoMapping,
        public bool $isExactMatch,
        public string $fileToken,
        public int $totalRows,
    ) {}
}
