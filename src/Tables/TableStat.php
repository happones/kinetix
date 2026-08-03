<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables;

use Closure;
use Happones\Kinetix\Data\TableStatData;
use Happones\Kinetix\Support\Concerns\FormatsAggregateValue;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Support\Facades\Gate;

/**
 * A KPI card above a table — "Total books 12,480", "Overdue 34".
 *
 * The point of this class is that a card's condition compiles to a **conditional
 * aggregate** rather than a separate scoped query:
 *
 *     count(*)                                            as total
 *     sum(case when "status" = ? then 1 else 0 end)        as on_loan
 *     sum(case when "due_at" < ? then 1 else 0 end)        as overdue
 *
 * so twelve cards cost the same single extra query as one. That is the whole
 * difference between this and stacking `Summarizer::query()` scopes, where each
 * card would scan the table again — measurably +1 query per card.
 *
 * By default a card reflects the table's active filters, like the footer
 * summaries do. Call {@see ignoreFilters()} for a KPI that should always show the
 * dataset-wide figure.
 *
 * `using()` remains available for anything SQL can't express in one pass, at the
 * documented cost of its own query.
 */
class TableStat
{
    use FormatsAggregateValue;

    protected string $label;

    protected string $aggregate = 'count';

    protected ?string $column = null;

    /**
     * Conditions narrowing this card, as [column, operator, value] triples.
     *
     * @var array<int, array{0: string, 1: string, 2: mixed}>
     */
    protected array $conditions = [];

    /**
     * Conditions expressed as `column IS NULL` / `IS NOT NULL`.
     *
     * @var array<int, array{0: string, 1: bool}>
     */
    protected array $nullConditions = [];

    protected bool $ignoresFilters = false;

    protected ?string $icon = null;

    protected string $color = 'info';

    protected ?string $description = null;

    protected ?string $url = null;

    /**
     * @var (Closure(Builder): mixed)|null
     */
    protected ?Closure $using = null;

    protected bool|Closure $isVisible = true;

    protected bool|Closure $isHidden = false;

    protected string|Closure|bool|null $authorizeUsing = null;

    public function __construct(string $label)
    {
        $this->label = $label;
    }

    public static function make(string $label): static
    {
        return new static($label);
    }

    /**
     * Count matching rows (the default).
     */
    public function count(): static
    {
        $this->aggregate = 'count';
        $this->column    = null;

        return $this;
    }

    public function sum(string $column): static
    {
        $this->aggregate = 'sum';
        $this->column    = $column;

        return $this;
    }

    public function avg(string $column): static
    {
        $this->aggregate = 'avg';
        $this->column    = $column;

        return $this;
    }

    public function min(string $column): static
    {
        $this->aggregate = 'min';
        $this->column    = $column;

        return $this;
    }

    public function max(string $column): static
    {
        $this->aggregate = 'max';
        $this->column    = $column;

        return $this;
    }

    /**
     * Narrow this card to rows matching a condition. Chainable; conditions are
     * ANDed. Two-argument form implies `=`:
     *
     *     ->where('status', 'loan')
     *     ->where('due_at', '<', now())
     */
    public function where(string $column, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            $value    = $operator;
            $operator = '=';
        }

        $this->conditions[] = [$column, (string) $operator, $value];

        return $this;
    }

    public function whereNull(string $column): static
    {
        $this->nullConditions[] = [$column, true];

        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->nullConditions[] = [$column, false];

        return $this;
    }

    /**
     * Compute over the whole dataset, ignoring the table's active filters and
     * search — for a KPI that should not move when the user filters the list.
     *
     * Cards that ignore filters share one aggregate query with each other, so
     * this doesn't cost a query per card either.
     */
    public function ignoreFilters(bool $condition = true): static
    {
        $this->ignoresFilters = $condition;

        return $this;
    }

    /**
     * Produce the value from the query yourself. This cannot be folded into the
     * shared aggregate query, so it costs one query of its own — reach for it
     * only when the value isn't a plain (conditional) aggregate.
     *
     * @param Closure(Builder): mixed $callback
     */
    public function using(Closure $callback): static
    {
        $this->using = $callback;

        return $this;
    }

    /**
     * Lucide icon name shown in the card's badge (e.g. 'book', 'users').
     */
    public function icon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * Badge colour: primary, info, success, warning, danger, gray.
     */
    public function color(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Make the card a link (e.g. to a pre-filtered list).
     */
    public function url(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function visible(bool|Closure $condition = true): static
    {
        $this->isVisible = $condition;

        return $this;
    }

    public function hidden(bool|Closure $condition = true): static
    {
        $this->isHidden = $condition;

        return $this;
    }

    /**
     * Restrict the card to users passing a Gate ability, a boolean or a closure.
     * A card the user may not see is never even computed — its aggregate is left
     * out of the query entirely.
     */
    public function can(string|Closure|bool $ability): static
    {
        $this->authorizeUsing = $ability;

        return $this;
    }

    /**
     * Whether this card renders for the current user.
     */
    public function shouldRender(): bool
    {
        if ($this->isHidden instanceof Closure ? (bool) ($this->isHidden)() : $this->isHidden) {
            return false;
        }

        if (! ($this->isVisible instanceof Closure ? (bool) ($this->isVisible)() : $this->isVisible)) {
            return false;
        }

        if ($this->authorizeUsing === null) {
            return true;
        }

        if (is_bool($this->authorizeUsing)) {
            return $this->authorizeUsing;
        }

        if ($this->authorizeUsing instanceof Closure) {
            return (bool) ($this->authorizeUsing)();
        }

        return Gate::allows($this->authorizeUsing);
    }

    public function ignoresFilters(): bool
    {
        return $this->ignoresFilters;
    }

    /**
     * Whether this card's value folds into the shared aggregate query.
     */
    public function isBatchable(): bool
    {
        return $this->using === null;
    }

    /**
     * The SQL aggregate for this card, plus its bindings.
     *
     * A conditional card becomes `sum(case when … then <value> else 0 end)`, so
     * it can ride along in the same SELECT as every other card.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function aggregateExpression(Grammar $grammar): array
    {
        $target = $this->aggregate === 'count'
            ? '1'
            : $grammar->wrap((string) $this->column);

        [$condition, $bindings] = $this->conditionSql($grammar);

        if ($condition === null) {
            return [
                $this->aggregate === 'count' ? 'count(*)' : "{$this->aggregate}({$target})",
                [],
            ];
        }

        // min/max have no meaningful "else" value, so they filter with a CASE
        // that yields NULL instead of 0 (NULL is ignored by both).
        $else = in_array($this->aggregate, ['count', 'sum'], true) ? '0' : 'null';
        $fn   = $this->aggregate === 'count' ? 'sum' : $this->aggregate;

        return ["{$fn}(case when {$condition} then {$target} else {$else} end)", $bindings];
    }

    /**
     * Compile the card's conditions into a SQL predicate + bindings.
     *
     * @return array{0: string|null, 1: array<int, mixed>}
     */
    protected function conditionSql(Grammar $grammar): array
    {
        $parts    = [];
        $bindings = [];

        foreach ($this->conditions as [$column, $operator, $value]) {
            // The operator is allowlisted rather than interpolated: everything
            // here is developer-authored, but the value still travels as a
            // binding and the operator can never become arbitrary SQL.
            $operator = $this->normalizeOperator($operator);

            if ($value instanceof Expression) {
                $parts[] = $grammar->wrap($column).' '.$operator.' '.$value->getValue($grammar);

                continue;
            }

            $parts[]    = $grammar->wrap($column).' '.$operator.' ?';
            $bindings[] = $value;
        }

        foreach ($this->nullConditions as [$column, $isNull]) {
            $parts[] = $grammar->wrap($column).($isNull ? ' is null' : ' is not null');
        }

        return $parts === []
            ? [null, []]
            : [implode(' and ', $parts), $bindings];
    }

    /**
     * @throws \InvalidArgumentException when the operator is not supported
     */
    protected function normalizeOperator(string $operator): string
    {
        $operator = strtolower(trim($operator));

        $allowed = ['=', '!=', '<>', '>', '>=', '<', '<=', 'like', 'not like'];

        if (! in_array($operator, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Unsupported operator [{$operator}] in a table stat. Allowed: ".implode(', ', $allowed).'.'
            );
        }

        return $operator;
    }

    /**
     * Resolve the value through the card's own query (the `using()` path).
     */
    public function resolveUsing(Builder $query): string
    {
        $value = $this->using !== null ? ($this->using)($query) : null;

        return $this->applyAffixes($this->format($value));
    }

    /**
     * Build the card payload from a value already fetched by the shared query.
     */
    public function toData(mixed $value): TableStatData
    {
        return new TableStatData(
            label: $this->label,
            value: $this->applyAffixes($this->format($value)),
            icon: $this->icon,
            color: $this->color,
            description: $this->description,
            url: $this->url,
        );
    }

    /**
     * Build the card payload from an already-formatted value (`using()`).
     */
    public function toDataFromFormatted(string $value): TableStatData
    {
        return new TableStatData(
            label: $this->label,
            value: $value,
            icon: $this->icon,
            color: $this->color,
            description: $this->description,
            url: $this->url,
        );
    }
}
