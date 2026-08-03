<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables;

use Closure;
use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Data\RecordModalsData;
use Happones\Kinetix\Data\SummaryData;
use Happones\Kinetix\Data\TableData;
use Happones\Kinetix\Data\TablePaginationData;
use Happones\Kinetix\Data\TableRowData;
use Happones\Kinetix\Data\TableStateData;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Infolists\Infolist;
use Happones\Kinetix\Query\KinetixQuery;
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Tables\Columns\Column;
use Happones\Kinetix\Tables\Columns\IconColumn;
use Happones\Kinetix\Tables\Columns\ImageColumn;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Filters\Filter;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
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

    /**
     * Skip the `COUNT(*)` and report only whether a next page exists.
     * See {@see simplePaginated()}.
     */
    protected bool $simplePaginate = false;

    /**
     * Seek-based pagination (`WHERE key > …` instead of `OFFSET`).
     * See {@see cursorPaginated()}.
     */
    protected bool $cursorPaginate = false;

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
     * When true, the full (capped) result set is shipped to the client and a
     * TanStack-backed renderer does search / sort / pagination in the browser —
     * no round-trip per interaction. Best for small, fully-loadable datasets.
     */
    protected bool $clientSide = false;

    /**
     * Safety cap on rows serialized in client-side mode, so an unbounded table
     * can never dump the whole database into the payload.
     */
    protected int $clientSideMax = 500;

    /**
     * Resource class backing in-table modal CRUD (create/edit/view), or null
     * when the table doesn't host record modals. Set via {@see recordModals()}.
     *
     * @var class-string<\Happones\Kinetix\Resources\Resource>|null
     */
    protected ?string $recordModalsResource = null;

    /**
     * Where the edit modal reads its record data: 'server' (fetch a fresh copy)
     * or 'row' (prefill from the already-loaded row). Null inherits the config
     * default (`kinetix.tables.record_source`).
     */
    protected ?string $recordModalsSource = null;

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

    /**
     * Paginate without counting the whole result set.
     *
     * A normal `paginate()` runs a `COUNT(*)` over the filtered query on every
     * page load to know the total — on a large or expensive-to-filter table that
     * count dominates the request. Simple mode fetches one extra row instead to
     * learn whether a next page exists.
     *
     * The trade is what the footer can show: no total, no last page, so no
     * "showing 1–10 of 4,231" and no jump-to-last button. Prefer it for tables
     * measured in the hundreds of thousands of rows, where the count is the cost
     * and nobody jumps to page 4,000 anyway.
     */
    public function simplePaginated(bool $simple = true): static
    {
        $this->isPaginated    = true;
        $this->simplePaginate = $simple;

        return $this;
    }

    /**
     * Paginate by cursor — a seek (`WHERE (sort, id) > (…)`) instead of an
     * `OFFSET`.
     *
     * `OFFSET n` makes the database walk and discard n rows, so page 5,000 of a
     * large table is thousands of times more expensive than page 1. A cursor
     * jumps straight to the row through the sort's index, so every page costs
     * the same. It is the right mode for deep or infinite scrolling over big
     * tables.
     *
     * What you give up: page numbers and jump-to-page. Navigation is prev/next
     * only, and the URL carries an opaque `cursor=` instead of `page=2`, so a
     * shared link points at a position in a result set rather than at a page.
     *
     * Kinetix appends the primary key to the sort so the ordering is **total**.
     * Without that, paging a non-unique sort silently *skips rows* — the cursor
     * is built from the ORDER BY columns, so on a tie it steps past the rest of
     * the tied group. Sorts a cursor cannot express (a relation column, a custom
     * `sortable(using:)` resolver) fall back to {@see simplePaginated()} for
     * that request rather than paginating wrongly.
     */
    public function cursorPaginated(bool $cursor = true): static
    {
        $this->isPaginated    = true;
        $this->cursorPaginate = $cursor;

        return $this;
    }

    /**
     * Whether the resolved query's ordering can be expressed as a cursor.
     *
     * The cursor encodes each ORDER BY column's value from the last row, so a
     * subquery or raw order has nothing to encode — Laravel does not complain,
     * it just paginates incorrectly.
     *
     * @param Builder<covariant Model> $query
     */
    protected function supportsCursorPagination(Builder $query): bool
    {
        foreach ($query->getQuery()->orders ?? [] as $order) {
            if (! is_string($order['column'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Append the primary key to the ordering unless it is already there, making
     * the sort total so the cursor cannot step over tied rows.
     *
     * @param Builder<covariant Model> $query
     */
    protected function ensureTotalOrder(Builder $query): void
    {
        $model  = $query->getModel();
        $key    = $model->getKeyName();
        $orders = $query->getQuery()->orders ?? [];

        foreach ($orders as $order) {
            $column = $order['column'] ?? null;

            if ($column === $key || $column === $model->qualifyColumn($key)) {
                return;
            }
        }

        // Follow the last sort's direction so the sequence stays coherent.
        $direction = $orders === [] ? 'asc' : ($orders[array_key_last($orders)]['direction'] ?? 'asc');

        $query->orderBy($model->qualifyColumn($key), KinetixQuery::direction($direction));
    }

    /**
     * Render this table client-side: ship the full (capped) result set once and
     * let a TanStack-backed renderer handle search / sort / pagination in the
     * browser. Intended for small, fully-loadable datasets — for large data or
     * interactive server filters, keep the default server-driven mode.
     *
     * @param int $max Safety cap on rows serialized (default 500).
     */
    public function clientSide(bool $condition = true, int $max = 500): static
    {
        $this->clientSide    = $condition;
        $this->clientSideMax = $max;

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
     * Host create/edit/view modals inside the table itself, driven by the given
     * Resource's `form()` and `infolist()`. Paired with actions that call
     * `->modal('create'|'edit'|'view'|'delete')`, this lets a page render just
     * `<KinetixTable :table>` and get full modal CRUD — the frontend fetches a
     * fresh form/infolist per record from a signed Kinetix endpoint and persists
     * through it (guarded by the record token + the model's policy).
     *
     * @param class-string<\Happones\Kinetix\Resources\Resource> $resource
     * @param string|null                                        $source   'server' (fetch a fresh record, default) or
     *                                                                     'row' (prefill the edit form from the loaded row).
     */
    public function recordModals(string $resource, ?string $source = null): static
    {
        $this->recordModalsResource = $resource;
        $this->recordModalsSource   = $source;

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

        // Eager-load the relations behind dot-notation columns. Without this
        // `data_get($record, 'author.name')` lazy-loads once PER ROW — the N+1
        // the feature is supposed to avoid. Derived from the rendered columns,
        // so it stays in sync with what the payload actually reads.
        KinetixQuery::eagerLoad($query, array_map(
            static fn (Column $column): string => $column->getName(),
            $this->columns,
        ));

        // Apply searching (grouped OR, LIKE wildcards escaped).
        $search = $this->param('search');

        if ($search !== null && $search !== '') {
            KinetixQuery::search($query, (string) $search, array_values(array_map(
                static fn (Column $column): string => $column->getName(),
                array_filter($this->columns, static fn (Column $column): bool => $column->isSearchable()),
            )));
        }

        // Apply sorting
        $this->applySort($query);

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
     * Apply the active sort. The sort key is allowlisted against the defined,
     * `sortable()` columns (so an arbitrary query-string value can never reach
     * `orderBy` and blow up a query). Precedence:
     *   1. a column's custom `sortable(using: …)` resolver,
     *   2. a dot-notation relation column (`author.name`) → correlated subquery,
     *   3. a plain column → `orderBy`.
     * Falls back to the persisted manual order for reorderable tables.
     */
    protected function applySort(Builder $query): void
    {
        $sort      = $this->param('sort');
        $direction = KinetixQuery::direction($this->param('direction', 'asc'));

        if ($sort === null || $sort === '') {
            if ($this->reorderColumn !== null) {
                $query->orderBy($this->reorderColumn);
            }

            return;
        }

        $column = collect($this->columns)->first(
            fn (Column $c) => $c->getName() === (string) $sort && $c->isSortable()
        );

        if ($column === null) {
            return;
        }

        $using = $column->getSortUsing();
        if ($using !== null) {
            $using($query, $direction);

            return;
        }

        if (! str_contains((string) $sort, '.')) {
            $query->orderBy((string) $sort, $direction);

            return;
        }

        KinetixQuery::sortByRelation($query, (string) $sort, $direction);
    }

    /**
     * Convert the entire table configuration and data to TableData.
     */
    public function toData(): TableData
    {
        // Drop columns the current user may not see (visible()/hidden()/can())
        // BEFORE anything downstream runs: headers, row values, summaries,
        // search/sort application and the signed editable-columns list all read
        // $this->columns, so a gated column's data never reaches the payload —
        // and can't be probed through sorting or inline edits either.
        $this->columns = array_values(array_filter(
            $this->columns,
            static fn (Column $column): bool => $column->shouldRender(),
        ));

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

        if ($this->clientSide) {
            // Ship the full (capped) set; the browser paginates. Base-query
            // search/sort/filters still apply on load via getResolvedQuery().
            foreach ($query->limit($this->clientSideMax)->get() as $record) {
                $records[] = $this->formatRecord($record);
            }
        } elseif ($this->isPaginated) {
            $pageName = $this->queryPrefix.'page';

            // A cursor can only encode plain-column orders; anything else falls
            // back to simple pagination rather than paginating wrongly.
            $useCursor = $this->cursorPaginate && $this->supportsCursorPagination($query);

            if ($useCursor) {
                $this->ensureTotalOrder($query);
            }

            // Simple/cursor modes fetch perPage+1 rows to learn whether a next
            // page exists, instead of running a COUNT(*) over the filtered set.
            $paginator = match (true) {
                $useCursor => $query->cursorPaginate($perPage, ['*'], $this->queryPrefix.'cursor'),
                $this->simplePaginate,
                $this->cursorPaginate => $query->simplePaginate($perPage, ['*'], $pageName),
                default               => $query->paginate($perPage, ['*'], $pageName),
            };

            foreach ($paginator->items() as $record) {
                $records[] = $this->formatRecord($record);
            }

            $pagination = match (true) {
                $paginator instanceof CursorPaginator => new TablePaginationData(
                    perPage: $paginator->perPage(),
                    hasMore: $paginator->hasMorePages(),
                    // A cursor has no page number and no offsets to report.
                    currentPage: null,
                    nextCursor: $paginator->nextCursor()?->encode(),
                    prevCursor: $paginator->previousCursor()?->encode(),
                    onFirstPage: $paginator->onFirstPage(),
                ),
                $paginator instanceof LengthAwarePaginator => new TablePaginationData(
                    perPage: $paginator->perPage(),
                    hasMore: $paginator->hasMorePages(),
                    currentPage: $paginator->currentPage(),
                    total: $paginator->total(),
                    lastPage: $paginator->lastPage(),
                    from: $paginator->firstItem(),
                    to: $paginator->lastItem(),
                ),
                default => new TablePaginationData(
                    perPage: $paginator->perPage(),
                    hasMore: $paginator->hasMorePages(),
                    currentPage: $paginator->currentPage(),
                    from: $paginator->firstItem(),
                    to: $paginator->lastItem(),
                ),
            };
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
            clientSide: $this->clientSide,
            recordModals: $this->buildRecordModalsData(),
        );
    }

    /**
     * Build the in-table modal CRUD descriptor, or null when the table did not
     * opt in via {@see recordModals()}. The signed token carries the model +
     * resource so the record endpoint trusts them; the create form blueprint is
     * shipped inline for an instant "New" modal (no round-trip).
     */
    protected function buildRecordModalsData(): ?RecordModalsData
    {
        $resource = $this->recordModalsResource;

        if ($resource === null) {
            return null;
        }

        $modelClass = $this->getModelClass();
        $source     = $this->recordModalsSource
            ?? (string) config('kinetix.tables.record_source', 'server');

        /** @var Form $createForm */
        $createForm  = $resource::form(Form::make(new $modelClass)->operation('create'))->fill();
        $hasForm     = $createForm->getFields()                                               !== [];
        $hasInfolist = $resource::infolist(Infolist::make(new $modelClass))->toData()->schema !== [];

        return new RecordModalsData(
            enabled: true,
            token: Crypt::encrypt([
                'model'    => $modelClass,
                'resource' => $resource,
            ]),
            source: $source === 'row' ? 'row' : 'server',
            hasForm: $hasForm,
            hasInfolist: $hasInfolist,
            createForm: $hasForm ? $createForm->toArray() : null,
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
