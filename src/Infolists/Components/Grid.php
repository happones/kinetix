<?php

declare(strict_types=1);

namespace Happones\Kinetix\Infolists\Components;

use Happones\Kinetix\Data\InfolistEntryData;
use Happones\Kinetix\Infolists\Concerns\LaysOutChildren;
use Illuminate\Database\Eloquent\Model;

class Grid extends Component
{
    use LaysOutChildren;

    /**
     * @var array<int, Component>
     */
    protected array $schema = [];

    /** @var int|array<string, int> */
    protected int|array $columns = 12;

    /** @param int|array<string, int> $columns */
    public function __construct(int|array $columns = 12)
    {
        $this->columns = $columns;
    }

    /** @param int|array<string, int> $columns */
    public static function make(int|array $columns = 12): static
    {
        return new static($columns);
    }

    /**
     * Set the layout grid schema.
     *
     * @param array<int, Component> $components
     */
    public function schema(array $components): static
    {
        $this->schema = $components;

        return $this;
    }

    protected function getType(): string
    {
        return 'grid';
    }

    public function toData(string $operation, ?Model $record = null): ?InfolistEntryData
    {
        if ($this->isHidden($operation, $record)) {
            return null;
        }

        $childData = $this->childrenData($this->schema, $this->columns, $operation, $record);

        return new InfolistEntryData(
            type: $this->getType(),
            columnSpan: $this->columnSpan,
            schema: $childData,
            columns: $this->columns,
        );
    }
}
