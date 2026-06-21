<?php

declare(strict_types=1);

namespace Happones\Kinetix\Infolists\Components;

use Happones\Kinetix\Data\InfolistEntryData;
use Illuminate\Database\Eloquent\Model;

class Tabs extends Component
{
    protected mixed $label;

    /**
     * @var array<int, Tab>
     */
    protected array $tabs = [];

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
        $this->tabs = $tabs;

        return $this;
    }

    /**
     * Alias of tabs() to mirror the schema()-style API of other layouts.
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

    public function toData(string $operation, ?Model $record = null): ?InfolistEntryData
    {
        if ($this->isHidden($operation, $record)) {
            return null;
        }

        $tabsData = [];
        foreach ($this->tabs as $tab) {
            $data = $tab->toData($operation, $record);
            if ($data !== null) {
                $tabsData[] = $data;
            }
        }

        return new InfolistEntryData(
            type: $this->getType(),
            columnSpan: $this->columnSpan,
            schema: $tabsData,
            heading: $this->label !== null ? (string) $this->label : null,
        );
    }
}
