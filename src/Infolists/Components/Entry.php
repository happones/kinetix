<?php

declare(strict_types=1);

namespace Happones\Kinetix\Infolists\Components;

use BackedEnum;
use Closure;
use Happones\Kinetix\Data\InfolistEntryData;
use Happones\Kinetix\Support\Contracts\HasColor;
use Happones\Kinetix\Support\Contracts\HasIcon;
use Happones\Kinetix\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

abstract class Entry extends Component
{
    protected string $name;

    protected mixed $label = null;

    protected mixed $placeholder = null;

    protected mixed $default = null;

    protected ?Closure $getStateUsing = null;

    protected ?Closure $formatStateUsing = null;

    protected string|Closure|null $icon = null;

    protected string|Closure|null $color = null;

    protected string|Closure|null $url = null;

    protected bool $openUrlInNewTab = false;

    /**
     * @var array<string, string>
     */
    protected array $extraAttributes = [];

    /**
     * Memoized raw state keyed by record object id, so the state callback and
     * relationship lookups run only once per record during serialization.
     *
     * @var array<int|string, mixed>
     */
    private array $rawStateCache = [];

    public function __construct(string $name)
    {
        $this->name = $name;
        // Generate a human-friendly label from the attribute name.
        $this->label = (string) str(str_replace('.', ' ', $name))->headline();
    }

    public static function make(string $name): static
    {
        return new static($name);
    }

    public function label(mixed $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function placeholder(mixed $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function default(mixed $value): static
    {
        $this->default = $value;

        return $this;
    }

    public function state(Closure $callback): static
    {
        $this->getStateUsing = $callback;

        return $this;
    }

    public function formatStateUsing(Closure $callback): static
    {
        $this->formatStateUsing = $callback;

        return $this;
    }

    public function icon(string|Closure $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function color(string|Closure $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function url(string|Closure $url, bool $shouldOpenInNewTab = false): static
    {
        $this->url = $url;
        $this->openUrlInNewTab = $shouldOpenInNewTab;

        return $this;
    }

    public function openUrlInNewTab(bool $condition = true): static
    {
        $this->openUrlInNewTab = $condition;

        return $this;
    }

    /**
     * @param array<string, string> $attributes
     */
    public function extraAttributes(array $attributes): static
    {
        $this->extraAttributes = $attributes;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Resolve the raw state from the record without any display formatting.
     *
     * Supports dot-notation for relationship attributes.
     */
    public function getRawState(?Model $record = null): mixed
    {
        $cacheKey = $record !== null ? spl_object_id($record) : '__null__';

        if (array_key_exists($cacheKey, $this->rawStateCache)) {
            return $this->rawStateCache[$cacheKey];
        }

        if ($this->getStateUsing !== null) {
            $value = ($this->getStateUsing)($record);
        } else {
            $value = $record !== null ? data_get($record, $this->name) : null;
        }

        if ($value === null || $value === '') {
            $default = $this->default instanceof Closure ? ($this->default)($record) : $this->default;
            if ($default !== null) {
                $value = $default;
            }
        }

        return $this->rawStateCache[$cacheKey] = $value;
    }

    /**
     * Resolve the display state, applying the formatting callback if present.
     */
    public function getState(?Model $record = null): mixed
    {
        $value = $this->getRawState($record);

        if ($this->formatStateUsing !== null) {
            return ($this->formatStateUsing)($value, $record);
        }

        return $value;
    }

    /**
     * Resolve the icon name, honouring HasIcon contracts and closures.
     */
    public function getIcon(?Model $record = null): ?string
    {
        $raw = $this->getRawState($record);

        if ($raw instanceof HasIcon) {
            return $raw->getIcon();
        }

        if ($this->icon instanceof Closure) {
            $resolved = ($this->icon)($this->getState($record), $record);

            return $resolved !== null ? (string) $resolved : null;
        }

        return $this->icon;
    }

    /**
     * Resolve the color, honouring HasColor contracts and closures.
     */
    public function getColor(?Model $record = null): ?string
    {
        $raw = $this->getRawState($record);

        if ($raw instanceof HasColor) {
            return $raw->getColor();
        }

        if ($this->color instanceof Closure) {
            $resolved = ($this->color)($this->getState($record), $record);

            return $resolved !== null ? (string) $resolved : null;
        }

        return $this->color;
    }

    /**
     * Resolve the URL the entry links to, if any.
     */
    public function getUrl(?Model $record = null): ?string
    {
        if ($this->url instanceof Closure) {
            $resolved = ($this->url)($record);

            return $resolved !== null ? (string) $resolved : null;
        }

        return $this->url;
    }

    /**
     * Normalise enum-backed states to their scalar representation.
     */
    protected function resolveEnumState(mixed $value): mixed
    {
        if ($value instanceof HasLabel) {
            return $value->getLabel();
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        return $value;
    }

    public function toData(string $operation, ?Model $record = null): ?InfolistEntryData
    {
        if ($this->isHidden($operation, $record)) {
            return null;
        }

        $label = $this->label instanceof Closure ? ($this->label)($record) : $this->label;
        $placeholder = $this->placeholder instanceof Closure ? ($this->placeholder)($record) : $this->placeholder;
        $extra = $this->getExtraData($record);

        return new InfolistEntryData(
            type: $this->getType(),
            name: $this->name,
            label: $label !== null ? (string) $label : null,
            columnSpan: $this->columnSpan,
            state: $this->getState($record),
            placeholder: $placeholder !== null ? (string) $placeholder : null,
            icon: $this->getIcon($record),
            color: $this->getColor($record),
            url: $this->getUrl($record),
            openUrlInNewTab: $this->openUrlInNewTab,
            isBadge: $extra['isBadge'] ?? null,
            isCopyable: $extra['isCopyable'] ?? null,
            isCircular: $extra['isCircular'] ?? null,
            size: $extra['size'] ?? null,
            isInline: $extra['isInline'] ?? false,
            extraAttributes: $this->extraAttributes ?: null,
        );
    }

    /**
     * Extra serialized attributes provided by concrete entry subclasses.
     *
     * @return array<string, mixed>
     */
    protected function getExtraData(?Model $record = null): array
    {
        return [];
    }
}
