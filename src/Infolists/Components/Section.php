<?php

declare(strict_types=1);

namespace Happones\Kinetix\Infolists\Components;

use Closure;
use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Data\InfolistEntryData;
use Illuminate\Database\Eloquent\Model;

class Section extends Component
{
    protected mixed $heading;

    protected mixed $description = null;

    protected string|Closure|null $icon = null;

    /**
     * @var array<int, Component>
     */
    protected array $schema = [];

    /**
     * Header actions rendered in the section's top-right corner.
     *
     * @var array<int, Action>
     */
    protected array $actions = [];

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

    public function icon(string|Closure $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * Set the inner schema of the section.
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

    /**
     * Set header actions for the section (e.g. an Edit button next to the title).
     *
     * @param array<int, Action> $actions
     */
    public function actions(array $actions): static
    {
        $this->actions = $actions;

        return $this;
    }

    protected function getType(): string
    {
        return 'section';
    }

    public function toData(string $operation, ?Model $record = null): ?InfolistEntryData
    {
        if ($this->isHidden($operation, $record)) {
            return null;
        }

        $heading     = $this->heading instanceof Closure ? ($this->heading)($record) : $this->heading;
        $description = $this->description instanceof Closure ? ($this->description)($record) : $this->description;
        $icon        = $this->icon instanceof Closure ? ($this->icon)($record) : $this->icon;

        $childData = [];
        foreach ($this->schema as $component) {
            $data = $component->toData($operation, $record);
            if ($data !== null) {
                $childData[] = $data;
            }
        }

        // Auth-filtered header actions, resolved against the record.
        $actionsData = array_values(array_filter(array_map(
            fn (Action $action) => $action->toData($record),
            $this->actions,
        )));

        return new InfolistEntryData(
            type: $this->getType(),
            columnSpan: $this->columnSpan,
            icon: $icon !== null ? (string) $icon : null,
            schema: $childData,
            heading: $heading         !== null ? (string) $heading : null,
            description: $description !== null ? (string) $description : null,
            columns: $this->columns,
            actions: $actionsData !== [] ? $actionsData : null,
        );
    }
}
