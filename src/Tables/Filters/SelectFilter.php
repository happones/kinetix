<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Filters;

use Closure;
use Happones\Kinetix\Forms\SearchScope;
use Happones\Kinetix\Query\SearchMode;
use Happones\Kinetix\Support\Contracts\HasLabel;
use Happones\Kinetix\Support\DescriptorAudience;
use Happones\Kinetix\Support\SignedDescriptor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class SelectFilter extends Filter
{
    protected bool|Closure $searchable = false;

    /**
     * @var array<string, string>|Closure|class-string
     */
    protected array|Closure|string $options = [];

    protected ?string $attribute = null;

    protected function getType(): string
    {
        return 'select';
    }

    /** @var class-string<Model>|null */
    protected ?string $searchModel = null;

    protected string $searchLabelColumn = 'name';

    /** @var array<int, string> */
    protected array $searchColumns = ['name'];

    protected ?SearchMode $searchMode = null;

    protected string $searchValueColumn = 'id';

    /** @var array<string, mixed> */
    protected array $searchScope = [];

    protected bool $teamScoped = true;

    protected bool $teamScopeGlobal = false;

    /** @var class-string|null */
    protected ?string $searchModifier = null;

    public function searchable(bool|Closure $condition = true): static
    {
        $this->searchable = $condition;

        return $this;
    }

    /**
     * Search a model remotely (server-filtered, debounced & lazy on the client).
     *
     * @param class-string<Model> $model
     * @param array<int, string>  $searchColumns
     * @param class-string|null   $modifyQueryUsing an invokable class receiving the query builder; a
     *                                              class-string because the descriptor round-trips
     *                                              through the browser (never a closure)
     */
    public function searchUsing(string $model, string $labelColumn = 'name', array $searchColumns = ['name'], string $valueColumn = 'id', ?string $modifyQueryUsing = null): static
    {
        if ($modifyQueryUsing !== null && ! (class_exists($modifyQueryUsing) && method_exists($modifyQueryUsing, '__invoke'))) {
            throw new \InvalidArgumentException(sprintf(
                'The search modifier of the "%s" filter must be the class-string of an invokable class; got "%s".',
                $this->name,
                $modifyQueryUsing,
            ));
        }

        $this->searchModifier    = $modifyQueryUsing;
        $this->searchModel       = $model;
        $this->searchLabelColumn = $labelColumn;
        $this->searchColumns     = $searchColumns;
        $this->searchValueColumn = $valueColumn;
        $this->searchable        = true;

        return $this;
    }

    /**
     * How the remote search matches the term (see
     * {@see SearchMode}); sealed into the token.
     */
    public function searchMode(SearchMode $mode): static
    {
        $this->searchMode = $mode;

        return $this;
    }

    /**
     * Bound which records the remote search and the selected-label lookup may
     * return, sealed into the filter's token. Without it the model's rows are
     * only as private as its global scopes, so name the tenant key here:
     *
     *     SelectFilter::make('project_id')
     *         ->searchUsing(Project::class)
     *         ->searchScope(['team_id' => $team->getKey()])
     *
     * @param array<string, mixed> $constraints column => value (null = whereNull)
     */
    public function searchScope(array $constraints): static
    {
        $this->searchScope = $constraints;

        return $this;
    }

    /**
     * Opt this filter out of the automatic team bound (see
     * `Select::withoutTeamScope()`), sealed into its token.
     */
    public function withoutTeamScope(): static
    {
        $this->teamScoped = false;

        return $this;
    }

    /**
     * Keep the rows with no team visible next to the active team's (see
     * `Select::teamScopeIncludesGlobal()`), sealed into its token.
     */
    public function teamScopeIncludesGlobal(): static
    {
        $this->teamScopeGlobal = true;

        return $this;
    }

    public function options(array|Closure|string $options): static
    {
        $this->options = $options;

        return $this;
    }

    protected ?string $relationshipName = null;

    protected ?string $relationshipTitleColumn = null;

    protected ?Closure $modifyRelationshipQuery = null;

    /**
     * Filter by an Eloquent relationship (Filament-compatible): the options
     * come from the related model (`key => title column`) and the filter
     * applies a `whereHas` on the relation's key.
     *
     *     SelectFilter::make('author')->relationship('author', 'name');
     *     SelectFilter::make('author')->relationship('author', 'name', fn ($q) => $q->where('active', true));
     */
    public function relationship(string $name, string $titleColumn, ?Closure $modifyQueryUsing = null): static
    {
        $this->relationshipName        = $name;
        $this->relationshipTitleColumn = $titleColumn;
        $this->modifyRelationshipQuery = $modifyQueryUsing;

        return $this;
    }

    /**
     * Resolve the relationship's options from the related model's table.
     *
     * @return array<string, string>
     */
    protected function resolveRelationshipOptions(): array
    {
        if ($this->relationshipName === null || $this->modelClass === null) {
            return [];
        }

        $relation = (new $this->modelClass)->{$this->relationshipName}();
        $related  = $relation->getRelated();

        $query = SearchScope::apply($related->newQuery(), $this->searchScope, null, $this->teamScoped, $this->teamScopeGlobal);

        if ($this->modifyRelationshipQuery !== null) {
            SearchScope::modify($query, $this->modifyRelationshipQuery);
        }

        // Capped: unbounded, a relation over a large table puts every row in
        // the page payload. Past the cap, narrow it with the modifier closure.
        $limit   = (int) config('kinetix.forms.relationship_options_limit', 200);
        $options = [];

        foreach ($query->limit($limit)->get() as $row) {
            $options[(string) $row->getKey()] = (string) data_get($row, $this->relationshipTitleColumn);
        }

        if (count($options) === $limit) {
            Log::warning(sprintf(
                'Kinetix: the "%s" filter\'s relationship options hit the %d-row cap, so the list is '
                .'incomplete. Narrow it with the third argument of relationship(), or raise '
                .'kinetix.forms.relationship_options_limit.',
                $this->name,
                $limit,
            ));
        }

        return $options;
    }

    /**
     * Set the database column to filter by. Defaults to the filter name.
     */
    public function attribute(string $attribute): static
    {
        $this->attribute = $attribute;

        return $this;
    }

    /**
     * Resolve the labels for selected options from the database.
     *
     * @return array<string, string>
     */
    protected function resolveSelectedOptions(): array
    {
        if ($this->searchModel === null) {
            return [];
        }

        $selectedValue = null;
        foreach (request()->all() as $key => $val) {
            if (str_contains($key, 'filters') && is_array($val) && isset($val[$this->name])) {
                $selectedValue = $val[$this->name];
                break;
            }
        }

        if ($selectedValue === null || $selectedValue === '' || $selectedValue === []) {
            return [];
        }

        $values = is_array($selectedValue) ? $selectedValue : [$selectedValue];

        $rows = SearchScope::apply($this->searchModel::query(), $this->searchScope, $this->searchModifier, $this->teamScoped, $this->teamScopeGlobal)
            ->whereIn($this->searchValueColumn, $values)
            ->get();

        $options = [];
        foreach ($rows as $row) {
            $options[(string) data_get($row, $this->searchValueColumn)] = (string) data_get($row, $this->searchLabelColumn);
        }

        return $options;
    }

    /**
     * Get the options array.
     *
     * @return array<string, string>
     */
    public function getOptions(): array
    {
        if ($this->searchModel !== null) {
            return $this->resolveSelectedOptions();
        }

        if ($this->relationshipName !== null) {
            return $this->resolveRelationshipOptions();
        }

        if ($this->options instanceof Closure) {
            return ($this->options)();
        }

        if (is_string($this->options) && is_subclass_of($this->options, \UnitEnum::class)) {
            $options = [];
            foreach ($this->options::cases() as $case) {
                $label = ($case instanceof HasLabel || method_exists($case, 'getLabel'))
                    ? $case->getLabel()
                    : ($case instanceof \BackedEnum ? $case->value : $case->name);

                $value                    = $case instanceof \BackedEnum ? $case->value : $case->name;
                $options[(string) $value] = $label;
            }

            return $options;
        }

        return is_array($this->options) ? $this->options : [];
    }

    public function apply(Builder $query, mixed $value): void
    {
        if ($this->query !== null) {
            parent::apply($query, $value);

            return;
        }

        if ($this->relationshipName !== null) {
            $query->whereHas($this->relationshipName, fn (Builder $q) => $q->whereKey($value));

            return;
        }

        $attribute = $this->attribute ?? $this->name;
        $query->where($attribute, $value);
    }

    protected function getExtraData(): array
    {
        $data = [
            'options'      => $this->getOptions(),
            'isSearchable' => $this->searchable instanceof Closure ? (bool) ($this->searchable)() : $this->searchable,
        ];

        if ($this->searchModel !== null) {
            $data['searchToken'] = SignedDescriptor::seal([
                'model'    => $this->searchModel,
                'label'    => $this->searchLabelColumn,
                'columns'  => $this->searchColumns,
                'value'    => $this->searchValueColumn,
                'scope'    => $this->searchScope,
                'modifier' => $this->searchModifier,
            ] + ($this->searchMode === null ? [] : ['mode' => $this->searchMode->value]) + ($this->teamScoped ? [] : ['team_scope' => false]) + ($this->teamScopeGlobal ? ['team_scope_global' => true] : []), DescriptorAudience::Search);
        }

        return $data;
    }
}
