<?php

declare(strict_types=1);

namespace Happones\Kinetix\Actions;

use Happones\Kinetix\Data\ActionData;
use Happones\Kinetix\Support\Concerns\HasAuthorization;
use Illuminate\Database\Eloquent\Model;

class ActionGroup
{
    use HasAuthorization;

    /**
     * @var array<int, Action>
     */
    protected array $actions = [];

    protected ?string $label = null;

    protected ?string $icon = 'ellipsis-vertical';

    protected string $color = 'gray';

    protected string $size = 'sm';

    protected string $viewType = 'button';

    /**
     * @param array<int, Action> $actions
     */
    public function __construct(array $actions = [])
    {
        $this->actions = $actions;
    }

    /**
     * @param array<int, Action> $actions
     */
    public static function make(array $actions = []): static
    {
        return new static($actions);
    }

    /**
     * @param array<int, Action> $actions
     */
    public function actions(array $actions): static
    {
        $this->actions = $actions;

        return $this;
    }

    /**
     * @return array<int, Action>
     */
    public function getActions(): array
    {
        return $this->actions;
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function icon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function color(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function size(string $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function button(): static
    {
        $this->viewType = 'button';

        return $this;
    }

    public function link(): static
    {
        $this->viewType = 'link';

        return $this;
    }

    /**
     * Serialize the group (and its nested actions) into ActionData.
     */
    public function toData(?Model $record = null): ?ActionData
    {
        if (! $this->shouldRender($record)) {
            return null;
        }

        $childData = [];
        foreach ($this->actions as $action) {
            $data = $action->toData($record);
            if ($data !== null) {
                $childData[] = $data;
            }
        }

        // Don't render an empty dropdown when every child is hidden/unauthorized
        // (only meaningful with a record — the record-less template pass keeps the
        // group so per-row serialization can still populate it).
        if ($record !== null && $childData === []) {
            return null;
        }

        return $this->groupData($childData);
    }

    /**
     * @param array<int, ActionData> $childData
     */
    protected function groupData(array $childData): ActionData
    {
        return new ActionData(
            name: $this->label !== null ? (string) str($this->label)->slug() : 'action-group',
            label: $this->label ?? '',
            icon: $this->icon,
            color: $this->color,
            size: $this->size,
            viewType: $this->viewType,
            type: 'group',
            actions: $childData,
        );
    }

    /**
     * Serialize the group for a place that never gets a record — a table's
     * toolbar or footer. The group and each child are judged as a
     * record-less run is ({@see Action::toDataWithoutRecord()}), and a group
     * left with no child doesn't render.
     *
     * @param class-string<Model>|null $modelClass the table's model
     */
    public function toDataWithoutRecord(?string $modelClass = null): ?ActionData
    {
        if (! $this->shouldRenderWithoutRecord($modelClass)) {
            return null;
        }

        $childData = array_values(array_filter(array_map(
            static fn (Action $action): ?ActionData => $action->toDataWithoutRecord($modelClass),
            $this->actions,
        )));

        return $childData === [] ? null : $this->groupData($childData);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->toData()->toArray();
    }
}
