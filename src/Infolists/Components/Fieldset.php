<?php

declare(strict_types=1);

namespace Happones\Kinetix\Infolists\Components;

use Closure;
use Happones\Kinetix\Data\InfolistEntryData;
use Happones\Kinetix\Infolists\Concerns\LaysOutChildren;
use Illuminate\Database\Eloquent\Model;

class Fieldset extends Component
{
    use LaysOutChildren;

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

        $childData = $this->childrenData($this->schema, $this->columns, $operation, $record);

        return new InfolistEntryData(
            type: $this->getType(),
            columnSpan: $this->columnSpan,
            schema: $childData,
            heading: $label !== null ? (string) $label : null,
            columns: $this->columns,
        );
    }
}
