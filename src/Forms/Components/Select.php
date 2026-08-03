<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Closure;
use Happones\Kinetix\Data\FormFieldData;
use Happones\Kinetix\Support\ConfigCallback;
use Happones\Kinetix\Support\Contracts\HasLabel;
use Happones\Kinetix\Support\Contracts\ResolvesRelationships;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Select extends Field implements ResolvesRelationships
{
    /**
     * @var array<string, string>|Closure|string
     */
    protected array|Closure|string $options = [];

    protected bool|Closure $searchable = false;

    /** @var class-string<Model>|null */
    protected ?string $searchModel = null;

    protected string $searchLabelColumn = 'name';

    /** @var array<int, string> */
    protected array $searchColumns = ['name'];

    protected string $searchValueColumn = 'id';

    /** @var class-string<Model>|null The model that owns this field. */
    protected ?string $ownerModel = null;

    protected ?string $relationshipName = null;

    protected string $relationshipTitleColumn = 'name';

    /** @var Closure|class-string|null */
    protected Closure|string|null $modifyRelationshipQuery = null;

    /**
     * Constraints bounding the remote-search query.
     *
     * @var array<string, mixed>
     */
    protected array $searchScope = [];

    /**
     * Bound which records remote search may return.
     *
     * A searchable Select queries the related model directly, so without a bound
     * it happily returns every tenant's rows 20 labels at a time. Declare the
     * tenant key here:
     *
     *     Select::make('project_id')
     *         ->relationship('project', 'name')
     *         ->searchable()
     *         ->searchScope(['team_id' => $request->user()->currentTeam->getKey()])
     *
     * For anything a plain equality can't express, use a query modifier instead.
     *
     * @param array<string, mixed> $constraints
     */
    public function searchScope(array $constraints): static
    {
        $this->searchScope = $constraints;

        return $this;
    }

    /**
     * @param class-string<Model> $modelClass
     */
    public function forModel(string $modelClass): static
    {
        $this->ownerModel = $modelClass;

        return $this;
    }

    /**
     * Draw the options from an Eloquent relationship (Filament-compatible).
     *
     * The relation already names the related model and its key, so this replaces
     * repeating them in `options()` / `searchUsing()`:
     *
     *     Select::make('author_id')->relationship('author', 'name');
     *     Select::make('author_id')->relationship('author', 'name', fn ($q) => $q->where('active', true));
     *
     * Inherited by {@see CheckboxList} and {@see Radio}. For a BelongsToMany the
     * options are the same — persisting the pivot stays the host's job.
     *
     * The owning model comes from the Form (`Form::model()`, or inferred from the
     * record it was filled with); without one the relation can't be resolved and
     * the field falls back to whatever `options()` holds.
     *
     * @param Closure|class-string|null $modifyQueryUsing A closure, or the
     *                                                    class-string of an invokable class. Only the class-string form
     *                                                    survives into a remote-search token — see {@see searchable()}.
     */
    public function relationship(string $name, string $titleColumn = 'name', Closure|string|null $modifyQueryUsing = null): static
    {
        $this->relationshipName        = $name;
        $this->relationshipTitleColumn = $titleColumn;
        $this->modifyRelationshipQuery = $modifyQueryUsing;

        return $this;
    }

    protected function getType(): string
    {
        return 'select';
    }

    /**
     * Set the dropdown options.
     *
     * @param array<string, string>|Closure|string $options
     */
    public function options(array|Closure|string $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Render the select as a searchable combobox. With no remote source the
     * provided options are filtered client-side.
     */
    public function searchable(bool|Closure $condition = true): static
    {
        $this->searchable = $condition;

        return $this;
    }

    /**
     * Search a model remotely (server-filtered, debounced & lazy on the client).
     * The descriptor is encrypted into a token, so only this declared model and
     * columns can ever be queried — never arbitrary input.
     *
     * @param class-string<Model> $model
     * @param array<int, string>  $searchColumns
     */
    public function searchUsing(string $model, string $labelColumn = 'name', array $searchColumns = ['name'], string $valueColumn = 'id'): static
    {
        $this->searchModel       = $model;
        $this->searchLabelColumn = $labelColumn;
        $this->searchColumns     = $searchColumns;
        $this->searchValueColumn = $valueColumn;
        $this->searchable        = true;

        return $this;
    }

    /**
     * Eagerly load the relation's rows as `key => title`.
     *
     * Capped: a relation with a large table would otherwise put every row in the
     * page payload. Past the cap, declare the field `searchable()` so the
     * options are fetched on demand instead.
     *
     * @return array<string, string>
     */
    protected function resolveRelationshipOptions(): array
    {
        $query = $this->relatedQuery();

        if ($query === null) {
            return [];
        }

        $limit   = (int) config('kinetix.forms.relationship_options_limit', 200);
        $options = [];

        foreach ($query->limit($limit)->get() as $row) {
            $options[(string) $row->getKey()] = (string) data_get($row, $this->relationshipTitleColumn);
        }

        return $options;
    }

    /**
     * The related model's query with the relationship modifier applied, or null
     * when there is no owning model to resolve the relation against.
     *
     * @return Builder<Model>|null
     */
    protected function relatedQuery(): mixed
    {
        if ($this->relationshipName === null || $this->ownerModel === null) {
            return null;
        }

        $owner = new $this->ownerModel;

        if (! method_exists($owner, $this->relationshipName)) {
            return null;
        }

        $query = $owner->{$this->relationshipName}()->getRelated()->newQuery();

        if ($this->modifyRelationshipQuery !== null) {
            $modifier = $this->modifyRelationshipQuery instanceof Closure
                ? $this->modifyRelationshipQuery
                : ConfigCallback::resolve($this->modifyRelationshipQuery);

            if ($modifier !== null) {
                $modifier($query);
            }
        }

        return $query;
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        $data = parent::toData($operation, $record);

        if ($data === null) {
            return null;
        }

        $data->isSearchable = $this->searchable instanceof Closure
            ? (bool) ($this->searchable)($record)
            : $this->searchable;

        $descriptor = $this->searchDescriptor();

        if ($descriptor !== null) {
            $data->searchToken = Crypt::encrypt($descriptor);
        }

        return $data;
    }

    /**
     * The encrypted descriptor the search endpoint runs against.
     *
     * `searchUsing()` states it directly; a searchable `relationship()` derives
     * it from the relation — the related class and its title column — so the two
     * never disagree.
     *
     * The relationship modifier only travels when it is an **invokable
     * class-string**: the token has to survive a round trip to the browser, and
     * a closure cannot be serialized. A closure therefore shapes the eagerly
     * loaded options but not the remote search, which is why the class-string
     * form exists.
     *
     * @return array<string, mixed>|null
     */
    protected function searchDescriptor(): ?array
    {
        if ($this->searchModel !== null) {
            return $this->bindDescriptor([
                'model'   => $this->searchModel,
                'label'   => $this->searchLabelColumn,
                'columns' => $this->searchColumns,
                'value'   => $this->searchValueColumn,
            ]);
        }

        if ($this->relationshipName === null || $this->ownerModel === null || $this->searchable === false) {
            return null;
        }

        $owner = new $this->ownerModel;

        if (! method_exists($owner, $this->relationshipName)) {
            return null;
        }

        $related = $owner->{$this->relationshipName}()->getRelated();

        return $this->bindDescriptor([
            'model'    => $related::class,
            'label'    => $this->relationshipTitleColumn,
            'columns'  => [$this->relationshipTitleColumn],
            'value'    => $related->getKeyName(),
            'modifier' => is_string($this->modifyRelationshipQuery) ? $this->modifyRelationshipQuery : null,
        ]);
    }

    /**
     * Attach the scope, the user the descriptor was minted for and an expiry, so
     * a descriptor lifted from one user's payload can't be replayed by another.
     *
     * @param  array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    protected function bindDescriptor(array $descriptor): array
    {
        $ttl = config('kinetix.tables.token_ttl', 1440);

        return $descriptor + [
            'scope'   => $this->searchScope,
            'user'    => auth()->id(),
            'expires' => is_numeric($ttl) && (int) $ttl > 0
                ? now()->getTimestamp() + ((int) $ttl * 60)
                : null,
        ];
    }

    /**
     * Get options list for frontend.
     *
     * @return array<string, string>|null
     */
    protected function getFieldOptions(?Model $record = null): ?array
    {
        // Remote: ship only the currently-selected option (so the combobox can
        // display it); the rest are fetched via the search endpoint.
        if ($this->searchModel !== null) {
            return $this->resolveSelectedOption($record);
        }

        if ($this->relationshipName !== null) {
            return $this->resolveRelationshipOptions();
        }

        if ($this->options instanceof Closure) {
            return ($this->options)($record);
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

        return $this->options;
    }

    /**
     * @return array<string, string>
     */
    protected function resolveSelectedOption(?Model $record): array
    {
        $value = $record !== null ? data_get($record, (string) $this->name) : null;

        if ($value === null || $value === '' || $this->searchModel === null) {
            return [];
        }

        $row = $this->searchModel::query()->where($this->searchValueColumn, $value)->first();

        if ($row === null) {
            return [];
        }

        return [(string) $value => (string) data_get($row, $this->searchLabelColumn)];
    }
}
