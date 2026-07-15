<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Columns;

use Closure;
use Happones\Kinetix\Data\ColumnData;
use Happones\Kinetix\Tables\Columns\Summarizers\Summarizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class Column
{
    protected string $name;

    protected string $label;

    protected bool $isSearchable = false;

    protected bool $isSortable = false;

    protected ?Closure $sortUsing = null;

    protected string $alignment = 'left'; // left, center, right

    protected bool $isToggleable = false;

    protected bool $isToggledHiddenByDefault = false;

    protected bool $isCopyable = false;

    protected ?Closure $formatStateUsing = null;

    protected mixed $stateUsing = null;

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

    /**
     * Mark the column sortable. Dot-notation names (`author.name`) sort by the
     * related column via a correlated subquery (BelongsTo / HasOne). For any
     * other case, pass a custom sort resolver:
     * `->sortable(using: fn (Builder $query, string $direction) => $query->orderBy(...))`.
     *
     * @param Closure(Builder, string): mixed|null $using
     */
    public function sortable(bool $condition = true, ?Closure $using = null): static
    {
        $this->isSortable = $condition;
        $this->sortUsing  = $using;

        return $this;
    }

    public function getSortUsing(): ?Closure
    {
        return $this->sortUsing;
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

    /**
     * Override how the raw cell state is resolved (Filament-compatible):
     * instead of reading the record attribute named after the column, use the
     * given Closure (`fn ($record) => …`) or constant. `formatStateUsing()`
     * still runs on the resolved state afterwards.
     *
     *     TextColumn::make('total')->state(fn (Order $o) => $o->subtotal + $o->tax);
     */
    public function state(mixed $state): static
    {
        $this->stateUsing = $state;

        return $this;
    }

    /**
     * Filament-compatible alias of {@see state()}.
     */
    public function getStateUsing(mixed $state): static
    {
        return $this->state($state);
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
        if ($this->stateUsing !== null) {
            $value = $this->stateUsing instanceof Closure
                ? ($this->stateUsing)($record)
                : $this->stateUsing;
        } else {
            $value = data_get($record, $this->name);
        }

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
            isConfidential: $extra['isConfidential']           ?? null,
            inputType: $extra['inputType']                     ?? null,
            placeholder: $extra['placeholder']                 ?? null,
            numberConfig: $extra['numberConfig']               ?? null,
            hasSummary: $this->hasSummarizers(),
            view: $extra['view'] ?? null,
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
