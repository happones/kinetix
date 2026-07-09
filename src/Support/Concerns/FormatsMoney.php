<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support\Concerns;

use NumberFormatter;

/**
 * Locale-aware currency formatting for table columns and infolist entries.
 *
 * Values render through intl's `NumberFormatter::CURRENCY` in the resolved
 * locale — `$1,234.56` in en, `1.234,56 US$` in es — falling back to a plain
 * `CODE 1,234.56` when ext-intl is unavailable. The locale resolves from the
 * `money()` argument, then the column/entry `locale()` (see FormatsDates,
 * expected on consumers), then the application locale.
 */
trait FormatsMoney
{
    protected ?string $currencyCode = null;

    protected int $moneyDivideBy = 1;

    protected ?string $moneyLocale = null;

    /**
     * Format as currency in the resolved locale (Filament-compatible).
     * `$divideBy` converts minor units (e.g. `100` when amounts are stored in
     * cents).
     */
    public function money(string $currency = 'USD', int $divideBy = 1, ?string $locale = null): static
    {
        $this->currencyCode  = strtoupper($currency);
        $this->moneyDivideBy = max(1, $divideBy);
        $this->moneyLocale   = $locale;

        return $this;
    }

    /**
     * Apply the configured currency formatting to a raw numeric value.
     * Non-numeric values pass through untouched.
     */
    protected function formatMoneyValue(mixed $value): mixed
    {
        if ($this->currencyCode === null || ! is_numeric($value)) {
            return $value;
        }

        $amount = (float) $value / $this->moneyDivideBy;

        if (class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter($this->resolveMoneyLocale(), NumberFormatter::CURRENCY);

            return (string) $formatter->formatCurrency($amount, $this->currencyCode);
        }

        return $this->currencyCode.' '.number_format($amount, 2);
    }

    protected function resolveMoneyLocale(): string
    {
        return $this->moneyLocale
            ?? $this->dateLocale        // the column/entry ->locale() (FormatsDates)
            ?? app()->getLocale();
    }
}
