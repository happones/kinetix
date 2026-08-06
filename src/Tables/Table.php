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
use Happones\Kinetix\Data\TableStatData;
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
     * Toolbar arrangement: 'auto' adapts to the TABLE's own width (stacked
     * below ~640px, inline above); 'inline' / 'stacked' pin one arrangement.
     */
    protected string $toolbarLayout = 'auto';

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
     * KPI cards rendered above the table.
     *
     * @var array<int, TableStat>
     */
    protected array $stats = [];

    /**
     * Policy ability enforced on inline cell edits and reordering. Null lets
     * {@see TableWriteController} fall back to `update` whenever the model has
     * a policy. Set via {@see writeAbility()}.
     */
    protected ?string $writeAbility = null;

    /**
     * Explicit constraints bounding which records the table's write endpoints
     * may touch. Empty means they are captured from the base query at mint
     * time. Set via {@see writeScope()}.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $writeScope = null;

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
     * The resource class record modals resolve through, when enabled.
     * Relation managers use this to REJECT the combination (the modal
     * endpoints bypass the parent relationship).
     */
    public function getRecordModalsResource(): ?string
    {
        return $this->recordModalsResource;
    }

    /**
     * KPI cards shown above the table — counts, sums and averages over the same
     * dataset the table lists.
     *
     *     Table::make(Book::query())
     *         ->stats([
     *             TableStat::make('Total books')->count()->icon('book'),
     *             TableStat::make('On loan')->count()->where('status', 'loan')->color('warning'),
     *             TableStat::make('Overdue')->count()->where('due_at', '<', now())->color('danger'),
     *         ])
     *
     * Every card's condition compiles into ONE shared aggregate query, so the
     * cost is +1 query whether there are two cards or twelve. Cards follow the
     * table's active filters unless they declare `ignoreFilters()`.
     *
     * @param array<int, TableStat> $stats
     */
    public function stats(array $stats): static
    {
        $this->stats = $stats;

        return $this;
    }

    /**
     * Policy ability to enforce on inline cell edits and drag-and-drop
     * reordering. By default the model's `update` ability is used whenever it
     * has a policy; pass an explicit ability to require something narrower.
     */
    public function writeAbility(string $ability): static
    {
        $this->writeAbility = $ability;

        return $this;
    }

    /**
     * Constrain which records the table's write endpoints may touch, so a
     * tampered record id resolves to a 404 instead of a cross-tenant write.
     *
     * Kinetix captures the base query's simple `where` clauses automatically, so
     * `Table::make(Post::where('team_id', $id))` is already bounded. Declare the
     * scope explicitly when the base query builds its constraints in a way that
     * can't be introspected — a global scope, a nested closure, or a join:
     *
     *     Table::make($this->postsQuery())
     *         ->writeScope(['team_id' => $request->user()->currentTeam->getKey()])
     *
     * @param array<string, mixed> $constraints
     */
    public function writeScope(array $constraints): static
    {
        $this->writeScope = $constraints;

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
     * Pin the toolbar arrangement. 'auto' (the default) stacks heading /
     * full-width search / control row when the table itself is narrow and
     * inlines everything on one row when it is wide; 'inline' or 'stacked'
     * force one arrangement at every width.
     *
     * @param 'auto'|'inline'|'stacked' $layout
     */
    public function toolbarLayout(string $layout): static
    {
        if (! in_array($layout, ['auto', 'inline', 'stacked'], true)) {
            throw new \InvalidArgumentException(
                "Unsupported toolbar layout [{$layout}]. Allowed: auto, inline, stacked."
            );
        }

        $this->toolbarLayout = $layout;

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
        // Always a FRESH builder. Applying the request's search/sort/filters to
        // the caller's own instance would leak them back out — a developer who
        // reused `$query` after rendering the table would silently get the
        // table's filters — and would double-apply them if this ran twice.
        $query = $this->getUnfilteredQuery();

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

        // Same window: the KPI cards read the filtered-but-unpaginated set, so
        // they describe the list the user is looking at rather than one page.
        $stats = $this->computeStats($query);

        // Paginate if enabled
        $records    = [];
        $pagination = null;

        $perPage = $this->resolvePerPage();

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
            model: $this->buildWriteDescriptor($editableColumns),
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
            stats: $stats,
            reorderable: $this->reorderColumn !== null,
            savedViewsKey: $this->savedViewsKey,
            toolbarLayout: $this->toolbarLayout,
            clientSide: $this->clientSide,
            recordModals: $this->buildRecordModalsData(),
        );
    }

    /**
     * The page size for this request, clamped so a crafted `?perPage=10000000`
     * can't hydrate the whole table into one Inertia payload. Values the table
     * itself offers via paginationPageOptions() are always honored; anything
     * else is capped at `kinetix.tables.max_per_page`.
     */
    protected function resolvePerPage(): int
    {
        $perPage = (int) $this->param('perPage', (string) $this->defaultPaginationPageOption);

        if ($perPage < 1) {
            return $this->defaultPaginationPageOption;
        }

        if (in_array($perPage, $this->paginationPageOptions, true)) {
            return $perPage;
        }

        $max = config('kinetix.tables.max_per_page', 200);

        if (! is_numeric($max) || (int) $max < 1) {
            return $perPage;
        }

        return min($perPage, (int) $max);
    }

    /**
     * Mint the signed descriptor the write endpoints trust.
     *
     * Beyond the model and the editable-columns allowlist, it carries everything
     * {@see TableWriteController} needs to fail closed without the client ever
     * naming a class: the resource (so records resolve through the resource's own
     * query), the scope bounding the lookup, the ability to enforce, the user it
     * was minted for (so a leaked token isn't replayable by someone else), and
     * an expiry.
     *
     * @param array<int, string> $editableColumns
     */
    protected function buildWriteDescriptor(array $editableColumns): string
    {
        $ttl = config('kinetix.tables.token_ttl', 1440);

        return Crypt::encrypt([
            'model'    => $this->getModelClass(),
            'columns'  => $editableColumns,
            'reorder'  => $this->reorderColumn,
            'resource' => $this->recordModalsResource,
            'scope'    => $this->writeScope ?? $this->captureWriteScope(),
            'ability'  => $this->writeAbility,
            'user'     => auth()->id(),
            'expires'  => is_numeric($ttl) && (int) $ttl > 0
                ? now()->getTimestamp() + ((int) $ttl * 60)
                : null,
        ]);
    }

    /**
     * Capture the base query's simple equality constraints so the write
     * endpoints resolve records through the same bounds the table rendered
     * under — without this, `Table::make(Post::where('team_id', $id))` would
     * hand out a descriptor good for every Post in the database.
     *
     * Only plain `where(column, value)` and `whereNull(column)` clauses are
     * readable this way; a table whose constraints live in a join or a nested
     * closure should declare them with {@see writeScope()}. Authorization is
     * enforced independently, so an uncapturable scope degrades to the host's
     * policy rather than to nothing.
     *
     * @return array<string, mixed>
     */
    protected function captureWriteScope(): array
    {
        if (! $this->queryOrModel instanceof Builder) {
            return [];
        }

        $scope = [];

        foreach ($this->queryOrModel->getQuery()->wheres as $where) {
            $type   = $where['type']   ?? null;
            $column = $where['column'] ?? null;

            if (! is_string($column)) {
                continue;
            }

            if ($type === 'Basic' && ($where['operator'] ?? null) === '=' && is_scalar($where['value'] ?? null)) {
                $scope[$column] = $where['value'];

                continue;
            }

            if ($type === 'Null') {
                $scope[$column] = null;
            }
        }

        return $scope;
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
        // Every plain aggregate shares one scan. Previously each summarizer ran
        // its own query, so a footer with sum + average + count over a filtered
        // table scanned it three times — and that is precisely the table where
        // the scan is expensive.
        $values = $this->fetchBatchedAggregates($baseQuery);

        $summaries = [];
        $index     = 0;

        foreach ($this->columns as $column) {
            if (! $column->hasSummarizers()) {
                continue;
            }

            $columnSummaries = [];

            foreach ($column->getSummarizers() as $summarizer) {
                $key = $index++;

                $result = $summarizer->isBatchable()
                    ? $summarizer->summarizeFromValues($values[$key] ?? [], $baseQuery)
                    : $summarizer->summarize(clone $baseQuery, $column->getName());

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
     * Run every batchable summarizer's aggregate in a single query.
     *
     * Returns the values keyed by the summarizer's position (the same order
     * {@see computeSummaries()} walks), then by the summarizer's own local name.
     *
     * @param  Builder<covariant Model>         $baseQuery
     * @return array<int, array<string, mixed>>
     */
    /**
     * Compute the KPI cards above the table.
     *
     * Cards are grouped by the dataset they read — the filtered query, or the
     * unfiltered one for `ignoreFilters()` — and each group resolves in a SINGLE
     * query, because a card's conditions become conditional aggregates rather
     * than a scoped query of their own. A `using()` card is the documented
     * exception and pays for its own query.
     *
     * @return array<int, TableStatData>
     */
    protected function computeStats(Builder $filteredQuery): array
    {
        if ($this->stats === []) {
            return [];
        }

        // A card the user may not see is dropped BEFORE anything is computed, so
        // its aggregate never reaches the query at all.
        $stats = array_values(array_filter(
            $this->stats,
            static fn (TableStat $stat): bool => $stat->shouldRender(),
        ));

        if ($stats === []) {
            return [];
        }

        $batched = [];
        $custom  = [];

        foreach ($stats as $index => $stat) {
            $stat->isBatchable()
                ? $batched[$stat->ignoresFilters() ? 'all' : 'filtered'][$index] = $stat
                : $custom[$index]                                                = $stat;
        }

        /** @var array<int, mixed> $values */
        $values = [];

        foreach ($batched as $dataset => $group) {
            // getUnfilteredQuery() returns a fresh builder every call, so asking
            // for it here — after the filters were applied to a clone — still
            // yields the pristine dataset.
            $query = $dataset === 'all' ? $this->getUnfilteredQuery() : $filteredQuery;

            foreach ($this->fetchStatAggregates($query, $group) as $index => $value) {
                $values[$index] = $value;
            }
        }

        $resolved = [];

        foreach ($stats as $index => $stat) {
            $resolved[$index] = isset($custom[$index])
                ? $stat->toDataFromFormatted($stat->resolveUsing(
                    $stat->ignoresFilters()
                        ? $this->getUnfilteredQuery()
                        : (clone $filteredQuery),
                ))
                : $stat->toData($values[$index] ?? null);
        }

        ksort($resolved);

        return array_values($resolved);
    }

    /**
     * Run one aggregate query covering every card in the group.
     *
     * @param  array<int, TableStat> $stats keyed by their position
     * @return array<int, mixed>     values keyed the same way
     */
    protected function fetchStatAggregates(Builder $baseQuery, array $stats): array
    {
        $grammar  = $baseQuery->getQuery()->getGrammar();
        $selects  = [];
        $bindings = [];
        $map      = [];

        foreach ($stats as $index => $stat) {
            [$expression, $statBindings] = $stat->aggregateExpression($grammar);

            $alias       = "kinetix_stat_{$index}";
            $selects[]   = "{$expression} as {$alias}";
            $bindings    = array_merge($bindings, $statBindings);
            $map[$alias] = $index;
        }

        if ($selects === []) {
            return [];
        }

        // Same reasoning as fetchBatchedAggregates(): aggregate off the base
        // builder with no eager loads, columns, orders or limit, which would be
        // invalid without a GROUP BY.
        $query                     = (clone $baseQuery)->toBase();
        $query->columns            = null;
        $query->orders             = null;
        $query->limit              = null;
        $query->offset             = null;
        $query->bindings['select'] = [];
        $query->bindings['order']  = [];

        $row = (array) ($query->selectRaw(implode(', ', $selects), $bindings)->first() ?? []);

        $values = [];

        foreach ($map as $alias => $index) {
            $values[$index] = $row[$alias] ?? null;
        }

        return $values;
    }

    /**
     * A FRESH builder for the table's dataset, before the request's
     * search/sort/filters are applied.
     *
     * Every caller gets its own instance — {@see getResolvedQuery()} applies the
     * request state to one of these rather than to the builder the developer
     * handed in, and an `ignoreFilters()` stat card reads another.
     *
     * @return Builder<Model>
     */
    protected function getUnfilteredQuery(): Builder
    {
        if ($this->queryOrModel instanceof Builder) {
            return clone $this->queryOrModel;
        }

        if (is_string($this->queryOrModel) && is_subclass_of($this->queryOrModel, Model::class)) {
            return $this->queryOrModel::query();
        }

        if ($this->queryOrModel instanceof Model) {
            return $this->queryOrModel->newQuery();
        }

        throw new \InvalidArgumentException('Invalid query or model type provided to Table.');
    }

    protected function fetchBatchedAggregates(Builder $baseQuery): array
    {
        $grammar = $baseQuery->getQuery()->getGrammar();
        $selects = [];
        $map     = [];
        $index   = 0;

        foreach ($this->columns as $column) {
            if (! $column->hasSummarizers()) {
                continue;
            }

            foreach ($column->getSummarizers() as $summarizer) {
                $key = $index++;

                if (! $summarizer->isBatchable()) {
                    continue;
                }

                foreach ($summarizer->aggregateExpressions($grammar->wrap($column->getName())) as $local => $expression) {
                    $alias       = "kinetix_agg_{$key}_{$local}";
                    $selects[]   = "{$expression} as {$alias}";
                    $map[$alias] = [$key, $local];
                }
            }
        }

        if ($selects === []) {
            return [];
        }

        // Aggregate off the BASE builder: no eager loads, and no columns/orders
        // /limit from the paginated read, which would be invalid without a
        // GROUP BY (Laravel's own aggregate() strips them for the same reason).
        $query                     = (clone $baseQuery)->toBase();
        $query->columns            = null;
        $query->orders             = null;
        $query->limit              = null;
        $query->offset             = null;
        $query->bindings['select'] = [];
        $query->bindings['order']  = [];

        $row = (array) ($query->selectRaw(implode(', ', $selects))->first() ?? []);

        $values = [];

        foreach ($map as $alias => [$key, $local]) {
            $values[$key][$local] = $row[$alias] ?? null;
        }

        return $values;
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
