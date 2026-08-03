<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Happones\Kinetix\Support\Concerns\HasAuthorization;
use Illuminate\Database\Eloquent\Model;

abstract class Component
{
    /**
     * Fluent `visible()`/`hidden()` (closures receive the record) plus
     * `authorize(string $ability, mixed $subject = null)` — a Gate-based
     * shorthand for permission/role-gated fields. Without an explicit
     * `$subject`, a record-dependent ability (e.g. `->authorize('update')`)
     * defers to `true` until a record exists (edit forms), exactly like
     * `Action::authorize()` — pass the subject explicitly for create-time
     * checks: `->authorize('create', Post::class)`.
     */
    use HasAuthorization;

    /**
     * @var int|string|array<string, int|string>
     */
    /**
     * How many of the parent schema's columns this component occupies.
     *
     * Defaults to 1, like Filament. Combined with the 1-column form root that
     * still means "full width" for a plain field; inside `Grid::make(2)` it
     * means half, with no annotation needed. Use `columnSpanFull()` to span the
     * whole row regardless of the parent's column count.
     */
    protected mixed $columnSpan = 1;

    /**
     * @var string|array<int, string>|null
     */
    protected string|array|null $hiddenOn = null;

    /**
     * @var string|array<int, string>|null
     */
    protected string|array|null $visibleOn = null;

    /**
     * Set the columns span.
     *
     * @param int|string|array<string, int|string> $span
     */
    public function columnSpan(mixed $span): static
    {
        $this->columnSpan = $span;

        return $this;
    }

    /**
     * Span the full row regardless of the schema's column count
     * (Filament-compatible shorthand for `columnSpan('full')`).
     */
    public function columnSpanFull(): static
    {
        return $this->columnSpan('full');
    }

    /**
     * Hide the component on specific operations.
     *
     * @param string|array<int, string> $operations
     */
    public function hiddenOn(string|array $operations): static
    {
        $this->hiddenOn = $operations;

        return $this;
    }

    /**
     * Show the component on specific operations.
     *
     * @param string|array<int, string> $operations
     */
    public function visibleOn(string|array $operations): static
    {
        $this->visibleOn = $operations;

        return $this;
    }

    /**
     * Determine if the component is hidden.
     */
    public function isHidden(string $operation, ?Model $record = null): bool
    {
        if ($this->hiddenOn !== null) {
            $hiddenOn = is_array($this->hiddenOn) ? $this->hiddenOn : [$this->hiddenOn];
            if (in_array($operation, $hiddenOn, true)) {
                return true;
            }
        }

        if ($this->visibleOn !== null) {
            $visibleOn = is_array($this->visibleOn) ? $this->visibleOn : [$this->visibleOn];
            if (! in_array($operation, $visibleOn, true)) {
                return true;
            }
        }

        return ! $this->shouldRender($record);
    }

    /**
     * Convert the component definition to FormFieldData.
     */
    abstract public function toData(string $operation, ?Model $record = null): ?FormFieldData;

    /**
     * Convert the component to array format.
     *
     * @return array<string, mixed>
     */
    public function toArray(string $operation = 'create', ?Model $record = null): array
    {
        $data = $this->toData($operation, $record);

        return $data !== null ? $data->toArray() : [];
    }

    abstract protected function getType(): string;
}
