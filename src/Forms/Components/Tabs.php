<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Happones\Kinetix\Support\QueryStringKeys;
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

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        if ($this->isHidden($operation, $record)) {
            return null;
        }

        $tabData = [];
        $keys    = [];
        foreach ($this->schema as $tab) {
            $data = $tab->toData($operation, $record);
            if ($data !== null) {
                $tabData[] = $data;
                $keys[]    = $tab->getUrlKey();
            }
        }

        if ($tabData === []) {
            return null;
        }

        $queryParam = QueryStringKeys::param($this->queryParam, 'tab', 'kinetix.forms.query_string.tabs');

        // Keyed whether or not the URL carries them: tours reveal and point
        // at tabs by key too.
        QueryStringKeys::assign($tabData, $keys, 'tab');

        return new FormFieldData(
            type: $this->getType(),
            columnSpan: $this->columnSpan,
            schema: $tabData,
            queryParam: $queryParam,
        );
    }
}
