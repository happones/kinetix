<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Illuminate\Database\Eloquent\Model;

class Grid extends Component
{
    /**
     * @var array<int, Component>
     */
    protected array $schema = [];

    protected int $columns = 2;

    public function __construct(int $columns = 2)
    {
        $this->columns = $columns;
    }

    public static function make(int $columns = 2): static
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

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        if ($this->isHidden($operation, $record)) {
            return null;
        }

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
            columns: $this->columns,
        );
    }
}
