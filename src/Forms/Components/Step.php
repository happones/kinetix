<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Closure;
use Happones\Kinetix\Data\FormFieldData;
use Illuminate\Database\Eloquent\Model;

/**
 * A single step inside a {@see Wizard}. Holds its own nested schema and the
 * metadata shown in the step indicator (label, description, icon).
 */
class Step extends Component
{
    protected mixed $label;

    protected mixed $description = null;

    protected ?string $icon = null;

    protected ?string $color = null;

    /**
     * @var array<int, Component>
     */
    protected array $schema = [];

    /** @var int|array<string, int> */
    protected int|array $columns = 1;

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

    public function description(mixed $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function icon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * Accent color for this step's indicator once active/complete
     * (`success`|`danger`|`warning`|`info`|`primary`|`gray`). Defaults to primary.
     */
    public function color(string $color): static
    {
        $this->color = $color;

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
        return 'wizard-step';
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        if ($this->isHidden($operation, $record)) {
            return null;
        }

        $label       = $this->label instanceof Closure ? ($this->label)($record) : $this->label;
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
            heading: (string) $label,
            description: $description !== null ? (string) $description : null,
            columns: $this->columns,
            icon: $this->icon,
            color: $this->color,
        );
    }
}
