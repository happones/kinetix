<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Columns;

/**
 * An inline-editable numeric column (Reka NumberField with stepper buttons),
 * saved through the cell-update endpoint. Supports min/max/step bounds and Intl
 * number formatting (decimal, percent, currency).
 *
 *     NumberInputColumn::make('stock')->min(0)->step(1);
 *     NumberInputColumn::make('price')->currency('USD');
 */
class NumberInputColumn extends Column
{
    protected ?float $min = null;

    protected ?float $max = null;

    protected float $step = 1.0;

    protected string $format = 'decimal';

    protected ?string $currency = null;

    protected ?int $minDecimals = null;

    protected ?int $maxDecimals = null;

    protected ?string $numberLocale = null;

    protected function getType(): string
    {
        return 'number-input';
    }

    public function min(float $min): static
    {
        $this->min = $min;

        return $this;
    }

    public function max(float $max): static
    {
        $this->max = $max;

        return $this;
    }

    public function step(float $step): static
    {
        $this->step = $step;

        return $this;
    }

    public function decimals(int $min, ?int $max = null): static
    {
        $this->minDecimals = $min;
        $this->maxDecimals = $max ?? $min;

        return $this;
    }

    public function percent(): static
    {
        $this->format = 'percent';

        return $this;
    }

    public function currency(string $currency): static
    {
        $this->format   = 'currency';
        $this->currency = $currency;

        return $this;
    }

    public function numberLocale(string $locale): static
    {
        $this->numberLocale = $locale;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getExtraData(): array
    {
        return [
            'numberConfig' => [
                'min'      => $this->min,
                'max'      => $this->max,
                'step'     => $this->step,
                'format'   => $this->format,
                'currency' => $this->currency,
                'decimals' => $this->minDecimals === null
                    ? null
                    : ['min' => $this->minDecimals, 'max' => $this->maxDecimals],
                'locale' => $this->numberLocale,
            ],
        ];
    }

    public function isEditable(): bool
    {
        return true;
    }
}
