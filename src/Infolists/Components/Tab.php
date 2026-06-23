<?php

declare(strict_types=1);

namespace Happones\Kinetix\Infolists\Components;

use Closure;
use Happones\Kinetix\Data\InfolistEntryData;
use Illuminate\Database\Eloquent\Model;

class Tab extends Component
{
    protected mixed $label;

    protected string|Closure|null $icon = null;

    /**
     * @var array<int, Component>
     */
    protected array $schema = [];

    protected int $columns = 12;

    public function __construct(mixed $label)
    {
        $this->label = $label;
    }

    public static function make(mixed $label): static
    {
        return new static($label);
    }

    public function icon(string|Closure $icon): static
    {
        $this->icon = $icon;

        return $this;
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
        return 'tab';
    }

    public function toData(string $operation, ?Model $record = null): ?InfolistEntryData
    {
        if ($this->isHidden($operation, $record)) {
            return null;
        }

        $label = $this->label instanceof Closure ? ($this->label)($record) : $this->label;
        $icon  = $this->icon instanceof Closure ? ($this->icon)($record) : $this->icon;

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
            icon: $icon !== null ? (string) $icon : null,
            schema: $childData,
            heading: $label !== null ? (string) $label : null,
            columns: $this->columns,
        );
    }
}
