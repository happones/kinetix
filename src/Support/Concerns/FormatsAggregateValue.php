<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support\Concerns;

use NumberFormatter;

/**
 * Number / money / affix formatting for a computed aggregate value.
 *
 * Shared by the table footer's summarizers and the stat cards above a table, so
 * a sum formatted as money reads identically in both places.
 */
trait FormatsAggregateValue
{
    protected ?string $prefix = null;

    protected ?string $suffix = null;

    protected bool $isNumeric = false;

    protected int $decimalPlaces = 0;

    protected ?string $numberLocale = null;

    protected ?string $currency = null;

    protected int $moneyDivideBy = 1;

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

    /**
     * Format as a localized number (thousands separators, fixed decimals).
     */
    public function numeric(int $decimalPlaces = 0, ?string $locale = null): static
    {
        $this->isNumeric     = true;
        $this->decimalPlaces = $decimalPlaces;
        $this->numberLocale  = $locale;

        return $this;
    }

    /**
     * Format as currency. Pass `divideBy: 100` for amounts stored in cents.
     */
    public function money(string $currency, int $divideBy = 1, ?string $locale = null): static
    {
        $this->currency      = strtoupper($currency);
        $this->moneyDivideBy = max(1, $divideBy);
        $this->numberLocale  = $locale;

        return $this;
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
}
