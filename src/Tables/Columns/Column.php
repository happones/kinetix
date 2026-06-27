<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Columns;

use Closure;
use Happones\Kinetix\Data\ColumnData;
use Happones\Kinetix\Tables\Columns\Summarizers\Summarizer;
use Illuminate\Database\Eloquent\Model;

abstract class Column
{
    protected string $name;

    protected string $label;

    protected bool $isSearchable = false;

    protected bool $isSortable = false;

    protected string $alignment = 'left'; // left, center, right

    protected bool $isToggleable = false;

    protected bool $isToggledHiddenByDefault = false;

    protected bool $isCopyable = false;

    protected ?Closure $formatStateUsing = null;

    /**
     * @var array<int, Summarizer>
     */
    protected array $summarizers = [];

    public function __construct(string $name)
    {
        $this->name = $name;
        // Generate human-friendly label from column name
        $this->label = (string) str(str_replace('.', ' ', $name))->headline();
    }

    public static function make(string $name): static
    {
        return new static($name);
    }

    /**
     * Show a click-to-copy affordance on the cell that copies its value to the
     * clipboard. Works on any column type.
     */
    public function copyable(bool $condition = true): static
    {
        $this->isCopyable = $condition;

        return $this;
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function searchable(bool $condition = true): static
    {
        $this->isSearchable = $condition;

        return $this;
    }

    public function sortable(bool $condition = true): static
    {
        $this->isSortable = $condition;

        return $this;
    }

    public function alignment(string $alignment): static
    {
        $this->alignment = $alignment;

        return $this;
    }

    public function toggleable(bool $isToggleable = true, bool $isToggledHiddenByDefault = false): static
    {
        $this->isToggleable             = $isToggleable;
        $this->isToggledHiddenByDefault = $isToggledHiddenByDefault;

        return $this;
    }

    public function formatStateUsing(Closure $callback): static
    {
        $this->formatStateUsing = $callback;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isSearchable(): bool
    {
        return $this->isSearchable;
    }

    public function isSortable(): bool
    {
        return $this->isSortable;
    }

    /**
     * Add one or more summarizers (Sum, Average, Count, Range, …) rendered in
     * the table footer and, for exports, the totals row.
     *
     * @param Summarizer|array<int, Summarizer> $summarizers
     */
    public function summarize(mixed $summarizers): static
    {
        $this->summarizers = is_array($summarizers) ? array_values($summarizers) : [$summarizers];

        return $this;
    }

    /**
     * @return array<int, Summarizer>
     */
    public function getSummarizers(): array
    {
        return $this->summarizers;
    }

    public function hasSummarizers(): bool
    {
        return $this->summarizers !== [];
    }

    /**
     * Resolve the cell state (value) from the Eloquent model. Supports
     * dot-notation for relationship fields.
     */
    public function getState(Model $record): mixed
    {
        $value = data_get($record, $this->name);

        if ($this->formatStateUsing !== null) {
            return ($this->formatStateUsing)($value, $record);
        }

        return $value;
    }

    /**
     * Convert the column definition to ColumnData.
     */
    public function toData(): ColumnData
    {
        $extra = $this->getExtraData();

        return new ColumnData(
            name: $this->name,
            label: $this->label,
            isSearchable: $this->isSearchable,
            isSortable: $this->isSortable,
            alignment: $this->alignment,
            isToggleable: $this->isToggleable,
            isToggledHiddenByDefault: $this->isToggledHiddenByDefault,
            type: $this->getType(),
            isCopyable: $extra['isCopyable']                   ?? ($this->isCopyable ?: null),
            isCircular: $extra['isCircular']                   ?? null,
            size: $extra['size']                               ?? null,
            isPreviewable: $extra['isPreviewable']             ?? null,
            options: $extra['options']                         ?? null,
            isBadge: $extra['isBadge']                         ?? null,
            descriptionPosition: $extra['descriptionPosition'] ?? null,
            inputType: $extra['inputType']                     ?? null,
            placeholder: $extra['placeholder']                 ?? null,
            numberConfig: $extra['numberConfig']               ?? null,
            hasSummary: $this->hasSummarizers(),
        );
    }

    /**
     * Get extra attributes for subclass columns.
     *
     * @return array<string, mixed>
     */
    protected function getExtraData(): array
    {
        return [];
    }

    /**
     * Whether this column supports inline editing via the cell-update endpoint.
     */
    public function isEditable(): bool
    {
        return false;
    }

    /**
     * Convert the column definition to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->toData()->toArray();
    }

    abstract protected function getType(): string;
}
