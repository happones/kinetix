<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables;

use Closure;
use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Data\TableData;
use Happones\Kinetix\Data\TablePaginationData;
use Happones\Kinetix\Data\TableStateData;
use Happones\Kinetix\Data\TableRowData;
use Happones\Kinetix\Tables\Columns\Column;
use Happones\Kinetix\Tables\Columns\IconColumn;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Filters\Filter;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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

    /**
     * Create a new table builder instance.
     *
     * @param Builder|Model|string $queryOrModel Eloquent query builder, model instance, or model class string.
     */
    public function __construct(mixed $queryOrModel)
    {
        $this->queryOrModel = $queryOrModel;
        $this->columns = $this->buildColumns();
        $this->filters = $this->buildFilters();
        $this->recordActions = $this->buildRecordActions();
        $this->toolbarActions = $this->buildToolbarActions();
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
        $search = request('search');
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
        $sort      = request('sort');
        $direction = request('direction', 'asc');
        if ($sort !== null && $sort !== '') {
            // Only allow direct columns to prevent join validation errors
            if (!str_contains($sort, '.')) {
                $query->orderBy($sort, $direction === 'desc' ? 'desc' : 'asc');
            }
        }

        // Apply active filters
        $activeFilters = request('filters', []);
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
        $query = $this->getResolvedQuery();

        // Paginate if enabled
        $records = [];
        $pagination = null;

        $perPage = (int) request('perPage', (string) $this->defaultPaginationPageOption);

        if ($this->isPaginated) {
            $paginator = $query->paginate($perPage);

            foreach ($paginator->items() as $record) {
                $records[] = $this->formatRecord($record);
            }

            $pagination = new TablePaginationData(
                total: $paginator->total(),
                perPage: $paginator->perPage(),
                currentPage: $paginator->currentPage(),
                lastPage: $paginator->lastPage(),
            );
        } else {
            $items = $query->get();

            foreach ($items as $record) {
                $records[] = $this->formatRecord($record);
            }
        }

        $columnsData = array_map(fn ($c) => $c->toData(), $this->columns);
        $filtersData = array_map(fn ($f) => $f->toData(), $this->filters);
        $recordActionsData = array_map(fn ($a) => $a->toData(), $this->recordActions);
        $toolbarActionsData = array_map(fn ($a) => $a->toData(), $this->toolbarActions);

        $state = new TableStateData(
            search: (string) request('search', ''),
            sort: (string) request('sort', ''),
            direction: (string) request('direction', 'asc'),
            filters: (array) request('filters', []),
            perPage: $perPage,
        );

        return new TableData(
            heading: $this->heading,
            description: $this->description,
            poll: $this->poll,
            isStriped: $this->isStriped,
            model: \Illuminate\Support\Facades\Crypt::encryptString($this->getModelClass()),
            columns: $columnsData,
            filters: $filtersData,
            recordActions: $recordActionsData,
            toolbarActions: $toolbarActionsData,
            records: $records,
            isPaginated: $this->isPaginated,
            paginationPageOptions: $this->paginationPageOptions,
            pagination: $pagination,
            state: $state,
        );
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
        $rowValues       = [];
        $rowIcons        = [];
        $rowIconColors   = [];
        $rowBadgeColors  = [];
        $rowDescriptions = [];

        foreach ($this->columns as $column) {
            $colName             = $column->getName();
            $state               = $column->getState($record);
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
        }

        $recordUrlStr = null;
        if ($this->recordUrl !== null) {
            $recordUrlStr = ($this->recordUrl)($record);
        }

        $resolvedActions = [];
        foreach ($this->recordActions as $action) {
            $resolvedActions[] = $action->toData($record);
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

        if ($this->queryOrModel instanceof Model) {
            return $this->queryOrModel::class;
        }

        return '';
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
