<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Closure;
use Happones\Kinetix\Data\FormFieldData;
use Illuminate\Database\Eloquent\Model;

class Section extends Component
{
    protected mixed $heading;

    protected mixed $description = null;

    /**
     * @var array<int, Component>
     */
    protected array $schema = [];

    protected int $columns = 12;

    public function __construct(mixed $heading)
    {
        $this->heading = $heading;
    }

    public static function make(mixed $heading): static
    {
        return new static($heading);
    }

    public function description(mixed $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Set the layout section inner schema.
     *
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
        return 'section';
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        if ($this->isHidden($operation, $record)) {
            return null;
        }

        $heading     = $this->heading instanceof Closure ? ($this->heading)($record) : $this->heading;
        $description = $this->description instanceof Closure ? ($this->description)($record) : $this->description;

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
            heading: $heading         !== null ? (string) $heading : null,
            description: $description !== null ? (string) $description : null,
            columns: $this->columns,
        );
    }
}
