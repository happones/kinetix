<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class TableData extends Data
{
    /**
     * @param array<int, ColumnData>                 $columns
     * @param array<int, FilterData>                 $filters
     * @param array<int, ActionData>                 $recordActions
     * @param array<int, ActionData>                 $toolbarActions
     * @param array<int, ActionData>                 $bulkActions
     * @param array<int, ActionData>                 $footerActions
     * @param array<int, TableRowData>               $records
     * @param array<int, int>                        $paginationPageOptions
     * @param array<string, array<int, SummaryData>> $summaries
     */
    public function __construct(
        public ?string $heading,
        public ?string $description,
        public ?string $poll,
        public bool $isStriped,
        public string $model,
        public array $columns,
        public array $filters,
        public array $recordActions,
        public array $toolbarActions,
        public array $bulkActions,
        public array $records,
        public bool $isPaginated,
        public array $paginationPageOptions,
        public ?TablePaginationData $pagination,
        public TableStateData $state,
        public string $queryPrefix = '',
        public bool $stickyActions = false,
        public array $footerActions = [],
        public array $summaries = [],
        public bool $hasSummaries = false,
        public bool $reorderable = false,
        public ?string $savedViewsKey = null,
        // Client-side mode: full row set shipped, browser handles interactions.
        public bool $clientSide = false,
    ) {}
}
