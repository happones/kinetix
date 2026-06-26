<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Illuminate\Database\Eloquent\Model;

/**
 * A tabbed container. Each child is a {@see Tab} with its own schema:
 *
 *     Tabs::make()->tabs([
 *         Tab::make('Profile')->schema([...]),
 *         Tab::make('Security')->icon('settings')->schema([...]),
 *     ]);
 */
class Tabs extends Component
{
    protected mixed $label;

    /**
     * @var array<int, Tab>
     */
    protected array $schema = [];

    public function __construct(mixed $label = null)
    {
        $this->label = $label;
    }

    public static function make(mixed $label = null): static
    {
        return new static($label);
    }

    /**
     * @param array<int, Tab> $tabs
     */
    public function tabs(array $tabs): static
    {
        $this->schema = $tabs;

        return $this;
    }

    /**
     * Alias of {@see tabs()} for consistency with other layout components.
     *
     * @param array<int, Tab> $tabs
     */
    public function schema(array $tabs): static
    {
        return $this->tabs($tabs);
    }

    protected function getType(): string
    {
        return 'tabs';
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        if ($this->isHidden($operation, $record)) {
            return null;
        }

        $tabData = [];
        foreach ($this->schema as $tab) {
            $data = $tab->toData($operation, $record);
            if ($data !== null) {
                $tabData[] = $data;
            }
        }

        if ($tabData === []) {
            return null;
        }

        return new FormFieldData(
            type: $this->getType(),
            columnSpan: $this->columnSpan,
            schema: $tabData,
        );
    }
}
