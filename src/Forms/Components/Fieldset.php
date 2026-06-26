<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Closure;
use Happones\Kinetix\Data\FormFieldData;
use Illuminate\Database\Eloquent\Model;

/**
 * A labelled, bordered group of fields (HTML <fieldset>/<legend>). Like a
 * lighter Section without the card chrome.
 */
class Fieldset extends Component
{
    protected mixed $label;

    /**
     * @var array<int, Component>
     */
    protected array $schema = [];

    protected int $columns = 12;

    public function __construct(mixed $label = null)
    {
        $this->label = $label;
    }

    public static function make(mixed $label = null): static
    {
        return new static($label);
    }

    /**
     * @param array<int, Component> $components
     */
    public function schema(array $components): static
    {
        $this->schema = $components;

        return $this;
    }

    public function columns(int $columns): static
    {
        $this->columns = $columns;

        return $this;
    }

    protected function getType(): string
    {
        return 'fieldset';
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        if ($this->isHidden($operation, $record)) {
            return null;
        }

        $label = $this->label instanceof Closure ? ($this->label)($record) : $this->label;

        $childData = [];
        foreach ($this->schema as $component) {
            $data = $component->toData($operation, $record);
            if ($data !== null) {
                $childData[] = $data;
            }
        }

        return new FormFieldData(
            type: $this->getType(),
            columnSpan: $this->columnSpan,
            schema: $childData,
            heading: $label !== null ? (string) $label : null,
            columns: $this->columns,
        );
    }
}
