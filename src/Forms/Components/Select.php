<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Closure;
use Happones\Kinetix\Data\FormFieldData;
use Happones\Kinetix\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Select extends Field
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

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        $data = parent::toData($operation, $record);

        if ($data === null) {
            return null;
        }

        $data->isSearchable = $this->searchable instanceof Closure
            ? (bool) ($this->searchable)($record)
            : $this->searchable;

        if ($this->searchModel !== null) {
            $data->searchToken = Crypt::encrypt([
                'model'   => $this->searchModel,
                'label'   => $this->searchLabelColumn,
                'columns' => $this->searchColumns,
                'value'   => $this->searchValueColumn,
            ]);
        }

        return $data;
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
