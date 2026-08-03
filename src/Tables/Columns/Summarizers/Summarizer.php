<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Columns\Summarizers;

use Closure;
use Happones\Kinetix\Data\SummaryData;
use Happones\Kinetix\Support\Concerns\FormatsAggregateValue;
use Illuminate\Database\Eloquent\Builder;

/**
 * Base "summarizer": computes a single aggregate value (sum/avg/count/range/…)
 * over a column's dataset, formats it, and returns it for the table footer and
 * export totals row. Subclasses implement {@see compute()}; a fully custom value
 * can be produced with {@see using()}.
 */
class Summarizer
{
    use FormatsAggregateValue;

    protected ?string $label = null;

    /**
     * @var (Closure(Builder): void)|null
     */
    protected ?Closure $scope = null;

    /**
     * @var (Closure(Builder): mixed)|null
     */
    protected ?Closure $using = null;

    protected bool|Closure $isHidden = false;

    protected bool|Closure $isVisible = true;

    public static function make(): static
    {
        return new static;
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Apply a scope to this summarizer's dataset (a clone of the table query).
     *
     * @param Closure(Builder): void $callback
     */
    public function query(Closure $callback): static
    {
        $this->scope = $callback;

        return $this;
    }

    /**
     * Produce a fully custom value from the (scoped) query builder.
     *
     * @param Closure(Builder): mixed $callback
     */
    public function using(Closure $callback): static
    {
        $this->using = $callback;

        return $this;
    }

    public function hidden(bool|Closure $condition = true): static
    {
        $this->isHidden = $condition;

        return $this;
    }

    public function visible(bool|Closure $condition = true): static
    {
        $this->isVisible = $condition;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    /**
     * Whether this summarizer's value can be folded into the table's single
     * aggregate query.
     *
     * A custom `query()` scope or `using()` callback changes the dataset (or
     * bypasses SQL aggregation entirely), so those keep their own query. Every
     * plain aggregate shares one scan.
     */
    public function isBatchable(): bool
    {
        return $this->scope === null
            && $this->using === null
            && $this->aggregateExpressions('x') !== [];
    }

    /**
     * The SQL aggregates this summarizer needs, keyed by a local name.
     *
     * Empty (the base) means "not a plain aggregate" — see {@see isBatchable()}.
     * `$column` arrives already quoted by the connection's grammar.
     *
     * @return array<string, string>
     */
    public function aggregateExpressions(string $column): array
    {
        return [];
    }

    /**
     * Render from values already fetched by the batched aggregate query, keyed
     * as in {@see aggregateExpressions()}.
     *
     * @param array<string, mixed> $values
     */
    public function summarizeFromValues(array $values, Builder $query): ?SummaryData
    {
        if ($this->isHiddenFor($query)) {
            return null;
        }

        return new SummaryData($this->label, $this->applyAffixes($this->formatValues($values)));
    }

    /**
     * Format the batched values into the displayed string. Single-aggregate
     * summarizers read `value`; {@see Range} overrides for its min/max pair.
     *
     * @param array<string, mixed> $values
     */
    protected function formatValues(array $values): string
    {
        return $this->format($values['value'] ?? null);
    }

    public function summarize(Builder $query, string $column): ?SummaryData
    {
        if ($this->scope !== null) {
            ($this->scope)($query);
        }

        if ($this->isHiddenFor($query)) {
            return null;
        }

        $value = $this->using !== null
            ? (string) ($this->using)($query)
            : $this->resolveValue($query, $column);

        return new SummaryData($this->label, $this->applyAffixes($value));
    }

    /**
     * Resolve the displayed value from the computed aggregate. Overridable by
     * summarizers (e.g. Range) that format more than a single scalar.
     */
    protected function resolveValue(Builder $query, string $column): string
    {
        return $this->format($this->compute($query, $column));
    }

    /**
     * Compute the raw aggregate value for this summarizer. The base returns
     * null (use {@see using()} for a custom value); subclasses override.
     */
    protected function compute(Builder $query, string $column): mixed
    {
        return null;
    }

    protected function isHiddenFor(Builder $query): bool
    {
        $hidden = $this->isHidden instanceof Closure ? (bool) ($this->isHidden)($query) : $this->isHidden;

        if ($hidden) {
            return true;
        }

        $visible = $this->isVisible instanceof Closure ? (bool) ($this->isVisible)($query) : $this->isVisible;

        return ! $visible;
    }
}
