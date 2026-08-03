<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Columns\Summarizers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The minimum and maximum value in the column's dataset, rendered as
 * "min – max" (or a single value when they're equal). Supports numeric/money
 * formatting (via the base), plus minimal textual / date-time difference modes.
 */
class Range extends Summarizer
{
    protected bool $excludeNull = true;

    protected bool $asDateTime = false;

    protected bool $asText = false;

    protected ?int $charLimit = null;

    public function excludeNull(bool $condition = true): static
    {
        $this->excludeNull = $condition;

        return $this;
    }

    public function minimalDateTimeDifference(): static
    {
        $this->asDateTime = true;

        return $this;
    }

    public function minimalTextualDifference(): static
    {
        $this->asText = true;

        return $this;
    }

    public function limit(int $characters): static
    {
        $this->charLimit = $characters;

        return $this;
    }

    /**
     * `excludeNull` does not appear here on purpose: SQL's MIN/MAX already skip
     * NULLs, so the `whereNotNull` it adds cannot change either value — which
     * keeps Range foldable into the shared aggregate query.
     *
     * @return array<string, string>
     */
    public function aggregateExpressions(string $column): array
    {
        return ['min' => "min({$column})", 'max' => "max({$column})"];
    }

    /**
     * @param array<string, mixed> $values
     */
    protected function formatValues(array $values): string
    {
        return $this->renderRange($values['min'] ?? null, $values['max'] ?? null);
    }

    protected function compute(Builder $query, string $column): mixed
    {
        return null; // Range resolves min/max directly in resolveValue().
    }

    protected function resolveValue(Builder $query, string $column): string
    {
        if ($this->excludeNull) {
            $query->whereNotNull($column);
        }

        return $this->renderRange($query->min($column), $query->max($column));
    }

    /**
     * Shared by the per-summarizer and the batched paths so both render the
     * range identically.
     */
    protected function renderRange(mixed $min, mixed $max): string
    {
        if ($min === null && $max === null) {
            return '';
        }

        if ($this->asDateTime) {
            return $this->dateTimeRange($min, $max);
        }

        if ($this->asText) {
            return $this->textualRange((string) $min, (string) $max);
        }

        $minLabel = $this->limitText($this->format($min));
        $maxLabel = $this->limitText($this->format($max));

        return $minLabel === $maxLabel ? $minLabel : "{$minLabel} – {$maxLabel}";
    }

    protected function dateTimeRange(mixed $min, mixed $max): string
    {
        $minDate = Carbon::parse((string) $min);
        $maxDate = Carbon::parse((string) $max);

        if ($minDate->equalTo($maxDate)) {
            return $minDate->isoFormat('LLL');
        }

        // Same calendar day → show the date once with both times; otherwise dates.
        if ($minDate->isSameDay($maxDate)) {
            return $minDate->isoFormat('LL').' '.$minDate->isoFormat('LT').' – '.$maxDate->isoFormat('LT');
        }

        return $minDate->isoFormat('LL').' – '.$maxDate->isoFormat('LL');
    }

    protected function textualRange(string $min, string $max): string
    {
        if ($min === $max) {
            return $this->limitText($min);
        }

        // Reveal each side up to the first differing character.
        $length = min(strlen($min), strlen($max));
        $diffAt = $length;
        for ($i = 0; $i < $length; $i++) {
            if ($min[$i] !== $max[$i]) {
                $diffAt = $i;
                break;
            }
        }

        $minLabel = substr($min, 0, $diffAt + 1);
        $maxLabel = substr($max, 0, $diffAt + 1);

        return $this->limitText($minLabel).' – '.$this->limitText($maxLabel);
    }

    protected function limitText(string $value): string
    {
        if ($this->charLimit === null || mb_strlen($value) <= $this->charLimit) {
            return $value;
        }

        return mb_substr($value, 0, $this->charLimit).'…';
    }
}
