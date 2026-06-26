<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Illuminate\Database\Eloquent\Model;

/**
 * A responsive flex row: children sit side-by-side from the `md` breakpoint up,
 * and stack on small screens. Each child's width follows its `columnSpan`
 * (a number = flex-grow weight; 'full' = equal share).
 *
 *     Split::make([
 *         TextInput::make('first')->columnSpan(1),
 *         TextInput::make('last')->columnSpan(1),
 *     ]);
 */
class Split extends Component
{
    /**
     * @var array<int, Component>
     */
    protected array $schema = [];

    /**
     * @param array<int, Component> $schema
     */
    public function __construct(array $schema = [])
    {
        $this->schema = $schema;
    }

    /**
     * @param array<int, Component> $schema
     */
    public static function make(array $schema = []): static
    {
        return new static($schema);
    }

    /**
     * @param array<int, Component> $components
     */
    public function schema(array $components): static
    {
        $this->schema = $components;

        return $this;
    }

    protected function getType(): string
    {
        return 'split';
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
        );
    }
}
