<?php

declare(strict_types=1);

namespace Happones\Kinetix\Infolists\Components;

use Happones\Kinetix\Data\InfolistEntryData;
use Happones\Kinetix\Support\QueryStringKeys;
use Illuminate\Database\Eloquent\Model;

class Tabs extends Component
{
    protected mixed $label;

    /**
     * @var array<int, Tab>
     */
    protected array $tabs = [];

    protected string|bool|null $queryParam = null;

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

    /**
     * Keep the open tab in the URL (`?tab=security`) so a reload or a shared
     * link reopens it. On by default (`kinetix.forms.query_string.tabs`);
     * pass a name to use another param — two groups on one page then keep
     * their own — or `false` to switch it off for this group.
     */
    public function persistInQueryString(string|bool $param = true): static
    {
        $this->queryParam = $param;

        return $this;
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
        $keys     = [];
        foreach ($this->tabs as $tab) {
            $data = $tab->toData($operation, $record);
            if ($data !== null) {
                $tabsData[] = $data;
                $keys[]     = $tab->getUrlKey();
            }
        }

        $queryParam = QueryStringKeys::param($this->queryParam, 'tab', 'kinetix.forms.query_string.tabs');

        // Keyed whether or not the URL carries them: tours reveal and point
        // at tabs by key too.
        QueryStringKeys::assign($tabsData, $keys, 'tab');

        return new InfolistEntryData(
            type: $this->getType(),
            columnSpan: $this->columnSpan,
            schema: $tabsData,
            heading: $this->label !== null ? (string) $this->label : null,
            queryParam: $queryParam,
        );
    }
}
