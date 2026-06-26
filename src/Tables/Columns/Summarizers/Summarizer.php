<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Columns\Summarizers;

use Closure;
use Happones\Kinetix\Data\SummaryData;
use Illuminate\Database\Eloquent\Builder;
use NumberFormatter;

/**
 * Base "summarizer": computes a single aggregate value (sum/avg/count/range/…)
 * over a column's dataset, formats it, and returns it for the table footer and
 * export totals row. Subclasses implement {@see compute()}; a fully custom value
 * can be produced with {@see using()}.
 */
class Summarizer
{
    protected ?string $label = null;

    /**
     * @var (Closure(Builder): void)|null
     */
    protected ?Closure $scope = null;

    /**
     * @var (Closure(Builder): mixed)|null
     */
    protected ?Closure $using = null;

    protected ?string $prefix = null;

    protected ?string $suffix = null;

    protected bool $isNumeric = false;

    protected int $decimalPlaces = 0;

    protected ?string $numberLocale = null;

    protected ?string $currency = null;

    protected int $moneyDivideBy = 1;

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

    public function prefix(string $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function suffix(string $suffix): static
    {
        $this->suffix = $suffix;

        return $this;
    }

    public function numeric(int $decimalPlaces = 0, ?string $locale = null): static
    {
        $this->isNumeric     = true;
        $this->decimalPlaces = $decimalPlaces;
        $this->numberLocale  = $locale;

        return $this;
    }

    public function money(string $currency, int $divideBy = 1, ?string $locale = null): static
    {
        $this->currency      = strtoupper($currency);
        $this->moneyDivideBy = max(1, $divideBy);
        $this->numberLocale  = $locale;

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
     * Compute and format this summarizer for the given (base) query + column.
     * Returns null when the summarizer is hidden.
     */
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

    protected function format(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($this->currency !== null) {
            return $this->formatMoney((float) $value / $this->moneyDivideBy);
        }

        if ($this->isNumeric) {
            return $this->formatNumber((float) $value);
        }

        return (string) $value;
    }

    protected function formatNumber(float $value): string
    {
        if (class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter($this->locale(), NumberFormatter::DECIMAL);
            $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $this->decimalPlaces);

            return (string) $formatter->format($value);
        }

        return number_format($value, $this->decimalPlaces);
    }

    protected function formatMoney(float $value): string
    {
        if (class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter($this->locale(), NumberFormatter::CURRENCY);

            return (string) $formatter->formatCurrency($value, (string) $this->currency);
        }

        return $this->currency.' '.number_format($value, 2);
    }

    protected function locale(): string
    {
        return $this->numberLocale
            ?? config('kinetix.tables.number_locale')
            ?? app()->getLocale();
    }

    protected function applyAffixes(string $value): string
    {
        return ($this->prefix ?? '').$value.($this->suffix ?? '');
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
