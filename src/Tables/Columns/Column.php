<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Columns;

use Closure;
use Happones\Kinetix\Data\ColumnData;
use Happones\Kinetix\Support\Concerns\HasAuthorization;
use Happones\Kinetix\Tables\Columns\Summarizers\Summarizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

abstract class Column
{
    use HasAuthorization;

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

    protected ?string $tooltip = null;

    /**
     * Static tooltip shown on hover over the cell (title attribute) — column
     * caveats, units, definitions. Per-record dynamic text belongs in
     * `description()` instead.
     */
    public function tooltip(string $tooltip): static
    {
        $this->tooltip = $tooltip;

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
     * Whether this column should be serialized at all (header + every cell).
     *
     * A column is a whole-column structure, not a per-row one — like Filament,
     * `visible()`/`hidden()`/`authorize()`/`can()` gate the entire column, not
     * individual cells (there is no per-record pass afterwards to catch a
     * deferred check). So, unlike actions, a column must NOT defer a
     * policy-ability `authorize('ability')` when no subject is given: with no
     * later per-row evaluation, deferring would mean the gate never runs and
     * the column always leaks. Here an abilitiy with no explicit subject is
     * checked against the current user with no subject (same contract as
     * `can()`), so a column gated by a user-level policy is actually stripped.
     */
    public function shouldRender(?Model $record = null): bool
    {
        // A column has no per-record pass to defer a visible()/hidden()
        // closure to — deferring meant it never ran and the column (values,
        // and an editable column's write access) always shipped.
        return $this->passesVisibilityWithoutDeferral($record)
            && $this->passesCan()
            && $this->passesColumnAuthorization($record);
    }

    /**
     * Column-level authorization: a boolean/closure gate behaves as in the
     * shared trait, but a policy-ability string with no explicit subject is
     * evaluated now (against the user, no subject) instead of being deferred,
     * because a column has no per-record authorization pass to fall back on.
     */
    protected function passesColumnAuthorization(?Model $record = null): bool
    {
        if ($this->authorizeUsing === null) {
            return true;
        }

        if (is_bool($this->authorizeUsing)) {
            return $this->authorizeUsing;
        }

        if ($this->authorizeUsing instanceof Closure) {
            return (bool) ($this->authorizeUsing)($record);
        }

        $subject = $this->authorizeArguments ?? $record;

        return Gate::forUser(auth()->user())
            ->allows($this->authorizeUsing, $subject);
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
            tooltip: $this->tooltip,
            isHtml: $extra['isHtml']                   ?? null,
            wrap: $extra['wrap']                       ?? null,
            openUrlInNewTab: $extra['openUrlInNewTab'] ?? null,
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
     * @var array<int, mixed>
     */
    protected array $rules = [];

    /**
     * Extra Laravel validation rules the inline-edit value must pass, on top of
     * the rule the column type derives for itself (a Select's `in:` list, a
     * Number's `numeric`/min/max, a Toggle's `boolean`). The rules are sealed
     * into the table's signed descriptor and enforced server-side in
     * {@see TableWriteController::cellUpdate()} — the client never supplies
     * them, so they can't be tampered with.
     *
     *     TextInputColumn::make('email')->rules(['email', 'max:255']);
     *
     * @param array<int, mixed> $rules
     */
    public function rules(array $rules): static
    {
        $this->rules = array_values($rules);

        return $this;
    }

    /**
     * The full server-side validation rule set for an inline edit of this
     * column: the type's own derived rules first, then any explicit
     * {@see rules()}. Editable subclasses override {@see getTypeRules()}.
     *
     * @return array<int, mixed>
     */
    public function getValidationRules(): array
    {
        return [...$this->getTypeRules(), ...$this->rules];
    }

    /**
     * The validation rules intrinsic to the column TYPE (empty for a plain,
     * non-editable column). Editable columns override this to constrain the
     * value to what their control can legitimately produce.
     *
     * @return array<int, mixed>
     */
    protected function getTypeRules(): array
    {
        return [];
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
