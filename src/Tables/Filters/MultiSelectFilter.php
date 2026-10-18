<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Filters;

use Illuminate\Database\Eloquent\Builder;

class MultiSelectFilter extends SelectFilter
{
    protected bool $isBulkToggleable = false;

    protected function getType(): string
    {
        return 'multi-select';
    }

    /**
     * A "Select all" checkbox above the options: it checks every option in
     * view (the ones a search left, when searchable), or clears them, and
     * shows a dash when only some are checked.
     */
    public function bulkToggleable(bool $condition = true): static
    {
        $this->isBulkToggleable = $condition;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getExtraData(): array
    {
        return [...parent::getExtraData(), 'isBulkToggleable' => $this->isBulkToggleable];
    }

    /**
     * Match records whose attribute is any of the selected values (whereIn).
     *
     * @param array<int, mixed>|mixed $value
     */
    public function apply(Builder $query, mixed $value): void
    {
        if ($this->query !== null) {
            ($this->query)($query, $value);

            return;
        }

        $values = is_array($value)
            ? array_values(array_filter($value, fn ($v) => $v !== null && $v !== ''))
            : array_filter([$value], fn ($v) => $v !== null && $v !== '');

        if ($values === []) {
            return;
        }

        if ($this->relationshipName !== null) {
            $query->whereHas($this->relationshipName, fn (Builder $q) => $q->whereKey($values));

            return;
        }

        $query->whereIn($this->attribute ?? $this->name, $values);
    }
}
