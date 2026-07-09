<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables;

use Closure;
use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Data\SummaryData;
use Happones\Kinetix\Data\TableData;
use Happones\Kinetix\Data\TablePaginationData;
use Happones\Kinetix\Data\TableRowData;
use Happones\Kinetix\Data\TableStateData;
use Happones\Kinetix\Tables\Columns\Column;
use Happones\Kinetix\Tables\Columns\IconColumn;
use Happones\Kinetix\Tables\Columns\ImageColumn;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Filters\Filter;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use JsonSerializable;

class Table implements Arrayable, JsonSerializable
{
    /**
     * @var Builder|Model|string
     */
    protected mixed $queryOrModel;

    /**
     * @var array<int, Column>
     */
    protected array $columns = [];

    /**
     * @var array<int, Filter>
     */
    protected array $filters = [];

    /**
     * @var array<int, Action>
     */
    protected array $recordActions = [];

    /**
     * @var array<int, Action>
     */
    protected array $toolbarActions = [];

    /**
     * @var array<int, Action>
     */
    protected array $bulkActions = [];

    /**
     * @var array<int, Action>
     */
    protected array $footerActions = [];

    protected ?string $heading = null;

    protected ?string $description = null;

    protected ?string $poll = null;

    protected int $defaultPaginationPageOption = 10;

    /**
     * @var array<int, int>
     */
    protected array $paginationPageOptions = [5, 10, 25, 50];

    protected bool $isPaginated = true;

    protected ?Closure $recordUrl = null;

    protected bool $isStriped = false;

    protected bool $stickyActions = false;

    /**
     * When set, rows can be drag-reordered and the new order is persisted to
     * this integer column. Null = not reorderable.
     */
    protected ?string $reorderColumn = null;

    /**
     * Optional prefix for this table's query-string params, so multiple tables
     * (e.g. relation managers) on one page don't clash. Empty = unprefixed.
     */
    protected string $queryPrefix = '';

    protected ?string $savedViewsKey = null;

    /**
     * Create a new table builder instance.
     *
     * @param Builder|Model|string $queryOrModel Eloquent query builder, model instance, or model class string.
     */
    public function __construct(mixed $queryOrModel)
    {
        $this->queryOrModel   = $queryOrModel;
        $this->columns        = $this->buildColumns();
        $this->filters        = $this->buildFilters();
        $this->recordActions  = $this->buildRecordActions();
        $this->toolbarActions = $this->buildToolbarActions();
        $this->bulkActions    = $this->buildBulkActions();
        $this->footerActions  = $this->buildFooterActions();
    }

    protected function buildColumns(): array
    {
        return [];
    }

    protected function buildFilters(): array
    {
        return [];
    }

    protected function buildRecordActions(): array
    {
        return [];
    }

    protected function buildToolbarActions(): array
    {
        return [];
    }

    protected function buildBulkActions(): array
    {
        return [];
    }

    protected function buildFooterActions(): array
    {
        return [];
    }

    public static function render(mixed $queryOrModel): array
    {
        return static::make($queryOrModel)->toArray();
    }

    /**
     * Static helper to instantiate a Table.
     *
     * @param Builder|Model|string $queryOrModel
     */
    public static function make(mixed $queryOrModel): static
    {
        return new static($queryOrModel);
    }

    /**
     * Set columns.
     *
     * @param array<int, Column> $columns
     */
    public function columns(array $columns): static
    {
        $this->columns = $columns;

        return $this;
    }

    /**
     * Set filters.
     *
     * @param array<int, Filter> $filters
     */
    public function filters(array $filters): static
    {
        $this->filters = $filters;

        return $this;
    }

    /**
     * Set actions available per row record.
     *
     * @param array<int, Action> $actions
     */
    public function recordActions(array $actions): static
    {
        $this->recordActions = $actions;

        return $this;
    }

    /**
     * Set actions displayed in the table toolbar header.
     *
     * @param array<int, Action> $actions
     */
    public function toolbarActions(array $actions): static
    {
        $this->toolbarActions = $actions;

        return $this;
    }

    /**
     * Alias of {@see toolbarActions()} — header-level actions (e.g. Create,
     * Import, Export) rendered in the table's top toolbar.
     *
     * @param array<int, Action> $actions
     */
    public function headerActions(array $actions): static
    {
        return $this->toolbarActions($actions);
    }

    /**
     * Set actions that operate on the selected rows.
     *
     * @param array<int, Action> $actions
     */
    public function bulkActions(array $actions): static
    {
        $this->bulkActions = $actions;

        return $this;
    }

    /**
     * Set actions rendered in a bar below the table (next to pagination), e.g.
     * "Export all". Place the same Action in both {@see bulkActions()} and here
     * (or the toolbar) to support both whole-table and selected-rows contexts —
     * the bulk invocation merges the selected `ids` into the action payload.
     *
     * @param array<int, Action> $actions
     */
    public function footerActions(array $actions): static
    {
        $this->footerActions = $actions;

        return $this;
    }

    public function heading(string $heading): static
    {
        $this->heading = $heading;

        return $this;
    }

    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function poll(string $poll): static
    {
        $this->poll = $poll;

        return $this;
    }

    public function defaultPaginationPageOption(int $perPage): static
    {
        $this->defaultPaginationPageOption = $perPage;

        return $this;
    }

    /**
     * Set page options or disable pagination.
     *
     * @param bool|array<int, int> $options
     */
    public function paginated(bool|array $options): static
    {
        if (is_bool($options)) {
            $this->isPaginated = $options;

            return $this;
        }

        $this->isPaginated           = true;
        $this->paginationPageOptions = $options;

        return $this;
    }

    public function recordUrl(Closure $callback): static
    {
        $this->recordUrl = $callback;

        return $this;
    }

    public function striped(bool $condition = true): static
    {
        $this->isStriped = $condition;

        return $this;
    }

    /**
     * Pin the record-actions column to the right edge so it stays visible while
     * the table scrolls horizontally.
     */
    public function stickyActions(bool $condition = true): static
    {
        $this->stickyActions = $condition;

        return $this;
    }

    /**
     * Enable drag-and-drop row reordering, persisting the new order to the
     * given integer column. Rows are ordered by it by default.
     */
    public function reorderable(string $column = 'sort_order'): static
    {
        $this->reorderColumn = $column;

        return $this;
    }

    /**
     * Namespace this table's query-string params (e.g. 'posts_' → posts_search, posts_page).
     */
    public function queryPrefix(string $prefix): static
    {
        $this->queryPrefix = $prefix;

        return $this;
    }

    /**
     * Enable per-user saved views (presets of search/filters/sort/columns) for
     * this table. The key namespaces the views; defaults to the model class.
     */
    public function saveViews(?string $key = null): static
    {
        $this->savedViewsKey = $key ?? $this->getModelClass();

        return $this;
    }

    /**
     * Read a request param using this table's prefix.
     */
    protected function param(string $key, mixed $default = null): mixed
    {
        return request($this->queryPrefix.$key, $default);
    }

    /**
     * Build and resolve the Eloquent query applying request inputs.
     */
    protected function getResolvedQuery(): Builder
    {
        $query = null;

        if ($this->queryOrModel instanceof Builder) {
            $query = $this->queryOrModel;
        } elseif (is_string($this->queryOrModel) && is_subclass_of($this->queryOrModel, Model::class)) {
            $query = $this->queryOrModel::query();
        } elseif ($this->queryOrModel instanceof Model) {
            $query = $this->queryOrModel->newQuery();
        } else {
            throw new \InvalidArgumentException('Invalid query or model type provided to Table.');
        }

        // Apply searching
        $search = $this->param('search');
        if ($search !== null && $search !== '') {
            $query->where(function (Builder $q) use ($search) {
                foreach ($this->columns as $column) {
                    if ($column->isSearchable()) {
                        $colName = $column->getName();
                        if (str_contains($colName, '.')) {
                            [$relation, $relationAttr] = explode('.', $colName, 2);
                            $q->orWhereHas($relation, function (Builder $relQ) use ($relationAttr, $search) {
                                $relQ->where($relationAttr, 'like', "%{$search}%");
                            });
                        } else {
                            $q->orWhere($colName, 'like', "%{$search}%");
                        }
                    }
                }
            });
        }

        // Apply sorting
        $sort      = $this->param('sort');
        $direction = $this->param('direction', 'asc');
        if ($sort !== null && $sort !== '') {
            // Only allow direct columns to prevent join validation errors
            if (! str_contains($sort, '.')) {
                $query->orderBy($sort, $direction === 'desc' ? 'desc' : 'asc');
            }
        } elseif ($this->reorderColumn !== null) {
            // Reorderable tables default to the persisted manual order.
            $query->orderBy($this->reorderColumn);
        }

        // Apply active filters
        $activeFilters = $this->param('filters', []);
        if (is_array($activeFilters)) {
            foreach ($activeFilters as $filterName => $value) {
                if ($value !== null && $value !== '') {
                    $filter = collect($this->filters)->first(fn ($f) => $f->getName() === $filterName);
                    if ($filter !== null) {
                        $filter->apply($query, $value);
                    }
                }
            }
        }

        return $query;
    }

    /**
     * Convert the entire table configuration and data to TableData.
     */
    public function toData(): TableData
    {
        // Filters may resolve relationship options against the table's model.
        foreach ($this->filters as $filter) {
            $filter->forModel($this->getModelClass());
        }

        $query = $this->getResolvedQuery();

        // Compute column summaries over the full filtered dataset, before
        // pagination narrows the query.
        [$summaries, $hasSummaries] = $this->computeSummaries($query);

        // Paginate if enabled
        $records    = [];
        $pagination = null;

        $perPage = (int) $this->param('perPage', (string) $this->defaultPaginationPageOption);

        if ($this->isPaginated) {
            $paginator = $query->paginate($perPage, ['*'], $this->queryPrefix.'page');

            foreach ($paginator->items() as $record) {
                $records[] = $this->formatRecord($record);
            }

            $pagination = new TablePaginationData(
                total: $paginator->total(),
                perPage: $paginator->perPage(),
                currentPage: $paginator->currentPage(),
                lastPage: $paginator->lastPage(),
                from: $paginator->firstItem(),
                to: $paginator->lastItem(),
            );
        } else {
            $items = $query->get();

            foreach ($items as $record) {
                $records[] = $this->formatRecord($record);
            }
        }

        $editableColumns = [];
        foreach ($this->columns as $column) {
            if ($column->isEditable()) {
                $editableColumns[] = $column->getName();
            }
        }

        $columnsData = array_map(fn ($c) => $c->toData(), $this->columns);
        $filtersData = array_map(fn ($f) => $f->toData(), $this->filters);
        // Drop actions the current user is not authorized to see.
        $recordActionsData  = array_values(array_filter(array_map(fn ($a) => $a->toData(), $this->recordActions)));
        $toolbarActionsData = array_values(array_filter(array_map(fn ($a) => $a->toData(), $this->toolbarActions)));
        $bulkActionsData    = array_values(array_filter(array_map(fn ($a) => $a->toData(), $this->bulkActions)));
        $footerActionsData  = array_values(array_filter(array_map(fn ($a) => $a->toData(), $this->footerActions)));

        $state = new TableStateData(
            search: (string) $this->param('search', ''),
            sort: (string) $this->param('sort', ''),
            direction: (string) $this->param('direction', 'asc'),
            filters: (array) $this->param('filters', []),
            perPage: $perPage,
        );

        return new TableData(
            heading: $this->heading,
            description: $this->description,
            poll: $this->poll,
            isStriped: $this->isStriped,
            stickyActions: $this->stickyActions,
            model: Crypt::encrypt([
                'model'   => $this->getModelClass(),
                'columns' => $editableColumns,
                'reorder' => $this->reorderColumn,
            ]),
            columns: $columnsData,
            filters: $filtersData,
            recordActions: $recordActionsData,
            toolbarActions: $toolbarActionsData,
            bulkActions: $bulkActionsData,
            footerActions: $footerActionsData,
            records: $records,
            isPaginated: $this->isPaginated,
            paginationPageOptions: $this->paginationPageOptions,
            pagination: $pagination,
            state: $state,
            queryPrefix: $this->queryPrefix,
            summaries: $summaries,
            hasSummaries: $hasSummaries,
            reorderable: $this->reorderColumn !== null,
            savedViewsKey: $this->savedViewsKey,
        );
    }

    /**
     * Compute each column's summarizers over the (filtered, unpaginated)
     * dataset. Returns [summaries keyed by column name, whether any exist].
     *
     * @return array{0: array<string, array<int, SummaryData>>, 1: bool}
     */
    protected function computeSummaries(Builder $baseQuery): array
    {
        $summaries = [];

        foreach ($this->columns as $column) {
            if (! $column->hasSummarizers()) {
                continue;
            }

            $columnSummaries = [];
            foreach ($column->getSummarizers() as $summarizer) {
                $result = $summarizer->summarize(clone $baseQuery, $column->getName());
                if ($result !== null) {
                    $columnSummaries[] = $result;
                }
            }

            if ($columnSummaries !== []) {
                $summaries[$column->getName()] = $columnSummaries;
            }
        }

        return [$summaries, $summaries !== []];
    }

    /**
     * Convert the entire table configuration and data to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->toData()->toArray();
    }

    /**
     * Map model instance to frontend-friendly record structure.
     */
    protected function formatRecord(Model $record): TableRowData
    {
        $rowValues         = [];
        $rowIcons          = [];
        $rowIconColors     = [];
        $rowBadgeColors    = [];
        $rowDescriptions   = [];
        $rowProgress       = [];
        $rowProgressColors = [];
        $rowViewProps      = [];

        foreach ($this->columns as $column) {
            $colName = $column->getName();
            // Image columns resolve their stored path to a disk URL.
            $state = $column instanceof ImageColumn
                ? $column->getImageUrl($record)
                : $column->getState($record);
            $rowValues[$colName] = $state;

            if ($column instanceof IconColumn) {
                $rowIcons[$colName]      = $column->getIcon($record);
                $rowIconColors[$colName] = $column->getIconColor($record);
            }

            if ($column instanceof TextColumn) {
                $rowDescriptions[$colName] = [
                    'text'     => $column->getDescription($record),
                    'position' => $column->toArray()['descriptionPosition'] ?? 'below',
                ];

                if ($column->toArray()['isBadge'] ?? false) {
                    $rowBadgeColors[$colName] = $column->getBadgeColor($record);
                }
            }

            if ($column instanceof Columns\ProgressColumn) {
                $rowProgress[$colName]       = $column->getProgress($record);
                $rowProgressColors[$colName] = $column->getProgressColor($record);
            }

            if ($column instanceof Columns\ViewColumn) {
                $rowViewProps[$colName] = $column->getViewProps($record);
            }
        }

        $recordUrlStr = null;
        if ($this->recordUrl !== null) {
            $recordUrlStr = ($this->recordUrl)($record);
        }

        $resolvedActions = [];
        foreach ($this->recordActions as $action) {
            $data = $action->toData($record);
            if ($data !== null) {
                $resolvedActions[] = $data;
            }
        }

        return new TableRowData(
            id: $record->getKey(),
            values: $rowValues,
            icons: $rowIcons,
            iconColors: $rowIconColors,
            badgeColors: $rowBadgeColors,
            descriptions: $rowDescriptions,
            recordUrl: $recordUrlStr,
            actions: $resolvedActions,
            progress: $rowProgress,
            progressColors: $rowProgressColors,
            viewProps: $rowViewProps,
        );
    }

    public function getModelClass(): string
    {
        if ($this->queryOrModel instanceof Builder) {
            return $this->queryOrModel->getModel()::class;
        }

        if (is_string($this->queryOrModel)) {
            return $this->queryOrModel;
        }

        return $this->queryOrModel::class;
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
