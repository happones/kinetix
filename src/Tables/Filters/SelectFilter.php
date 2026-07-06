<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Filters;

use Closure;
use Happones\Kinetix\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

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

    protected string $searchValueColumn = 'id';

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

    public function options(array|Closure|string $options): static
    {
        $this->options = $options;

        return $this;
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

        $rows = $this->searchModel::query()
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

        if ($this->options instanceof Closure) {
            return ($this->options)();
        }

        if (is_string($this->options) && is_subclass_of($this->options, \UnitEnum::class)) {
            $options = [];
            foreach ($this->options::cases() as $case) {
                $label = $case instanceof HasLabel
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
            $data['searchToken'] = Crypt::encrypt([
                'model'   => $this->searchModel,
                'label'   => $this->searchLabelColumn,
                'columns' => $this->searchColumns,
                'value'   => $this->searchValueColumn,
            ]);
        }

        return $data;
    }
}
