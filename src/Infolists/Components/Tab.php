<?php

declare(strict_types=1);

namespace Happones\Kinetix\Infolists\Components;

use Closure;
use Happones\Kinetix\Data\InfolistEntryData;
use Happones\Kinetix\Infolists\Concerns\LaysOutChildren;
use Illuminate\Database\Eloquent\Model;

class Tab extends Component
{
    use LaysOutChildren;

    protected mixed $label;

    protected string|Closure|null $icon = null;

    protected ?string $urlKey = null;

    /**
     * @var array<int, Component>
     */
    protected array $schema = [];

    /** @var int|array<string, int> */
    protected int|array $columns = 12;

    public function __construct(mixed $label)
    {
        $this->label = $label;
    }

    public static function make(mixed $label): static
    {
        return new static($label);
    }

    /**
     * This tab's name in the URL (`?tab=…`) when its group persists in
     * the query string. Defaults to the slug of the label — set it when the
     * label is translated, so a shared link works in every locale.
     */
    public function key(string $key): static
    {
        $this->urlKey = $key;

        return $this;
    }

    public function getUrlKey(): ?string
    {
        return $this->urlKey;
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

    /** @param int|array<string, int> $columns */
    public function columns(int|array $columns): static
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

        $childData = $this->childrenData($this->schema, $this->columns, $operation, $record);

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
