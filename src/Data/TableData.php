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
     * @param array<int, TableStatData>              $stats
     * @param array<int, GroupData>                  $groups
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
        // KPI cards above the table (Table::stats()). Empty = none.
        public array $stats = [],
        public bool $reorderable = false,
        public ?string $savedViewsKey = null,
        // Toolbar arrangement: 'auto' (container-adaptive) | 'inline' | 'stacked'.
        public string $toolbarLayout = 'auto',
        // Client-side mode: full row set shipped, browser handles interactions.
        public bool $clientSide = false,
        // In-table modal CRUD wiring (simple resources). Null = disabled.
        public ?RecordModalsData $recordModals = null,
        // Custom empty state (heading/description/icon/CTAs). Null = default text.
        public ?TableEmptyStateData $emptyState = null,
        // Signed descriptor for server-side (BulkAction) bulk actions: seals
        // name→class + the table's scope/resource/ability so the bulk endpoint
        // resolves ids in-scope and authorizes each record. Null = none.
        public ?string $bulkDescriptor = null,
        // Signed descriptor for server-side (FormAction) record/toolbar actions:
        // seals name→class + the table's scope/resource/ability so the form
        // endpoint reconstructs the form, resolves any record in-scope and
        // authorizes it before running the handler. Null = none.
        public ?string $formActionDescriptor = null,
        // Row-grouping definitions offered for this table (Table::groups()).
        // Empty = grouping disabled.
        public array $groups = [],
        // Column of the group active on load (Table::defaultGroup() or the
        // request's `?group=`). Null = ungrouped.
        public ?string $defaultGroup = null,
    ) {}
}
