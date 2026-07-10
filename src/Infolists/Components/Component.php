<?php

declare(strict_types=1);

namespace Happones\Kinetix\Infolists\Components;

use Happones\Kinetix\Data\InfolistEntryData;
use Happones\Kinetix\Support\Concerns\HasAuthorization;
use Illuminate\Database\Eloquent\Model;

abstract class Component
{
    /**
     * Fluent `visible()`/`hidden()` (closures receive the record) plus
     * `authorize(string $ability, mixed $subject = null)` — a Gate-based
     * shorthand for permission/role-gated entries. Without an explicit
     * `$subject`, a record-dependent ability defers to `true` until a
     * record exists, exactly like `Action::authorize()`.
     */
    use HasAuthorization;

    /**
     * @var int|string|array<string, int|string>
     */
    protected mixed $columnSpan = 'full';

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
     * Convert the component definition to InfolistEntryData.
     */
    abstract public function toData(string $operation, ?Model $record = null): ?InfolistEntryData;

    /**
     * Convert the component to array format.
     *
     * @return array<string, mixed>
     */
    public function toArray(string $operation = 'view', ?Model $record = null): array
    {
        $data = $this->toData($operation, $record);

        return $data !== null ? $data->toArray() : [];
    }

    abstract protected function getType(): string;
}
