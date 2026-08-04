<?php

declare(strict_types=1);

namespace Happones\Kinetix\Infolists\Components;

use Closure;
use Happones\Kinetix\Data\InfolistEntryData;
use Illuminate\Database\Eloquent\Model;

class Fieldset extends Component
{
    protected mixed $label;

    /**
     * @var array<int, Component>
     */
    protected array $schema = [];

    /** @var int|array<string, int> */
    protected int|array $columns = 12;

    public function __construct(mixed $label)
    {
        $this->label = $label;
    }

    public static function make(mixed $label): static
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

    /** @param int|array<string, int> $columns */
    public function columns(int|array $columns): static
    {
        $this->columns = $columns;

        return $this;
    }

    protected function getType(): string
    {
        return 'fieldset';
    }

    public function toData(string $operation, ?Model $record = null): ?InfolistEntryData
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

        return new InfolistEntryData(
            type: $this->getType(),
            columnSpan: $this->columnSpan,
            schema: $childData,
            heading: $label !== null ? (string) $label : null,
            columns: $this->columns,
        );
    }
}
