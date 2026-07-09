<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Happones\Kinetix\Support\KinetixLocale;
use Illuminate\Database\Eloquent\Model;

/**
 * A numeric input with increment/decrement stepper buttons, min/max/step bounds
 * and Intl number formatting (decimal, percent or currency). Built on Reka UI's
 * NumberField on the frontend.
 *
 *     NumberField::make('quantity')->min(0)->max(99)->step(1);
 *     NumberField::make('rate')->percent()->decimals(0, 2);
 *     NumberField::make('price')->currency('USD');
 */
class NumberField extends Field
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
        return 'number-field';
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

    /**
     * Set the fraction-digit bounds. Pass one value to fix the precision.
     */
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
    public function numberConfig(): array
    {
        return [
            'min'      => $this->min,
            'max'      => $this->max,
            'step'     => $this->step,
            'format'   => $this->format,
            'currency' => $this->currency,
            'decimals' => $this->minDecimals === null
                ? null
                : ['min' => $this->minDecimals, 'max' => $this->maxDecimals],
            'locale' => $this->numberLocale ?? KinetixLocale::bcp47(),
        ];
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        $data = parent::toData($operation, $record);

        if ($data === null) {
            return null;
        }

        $data->numberConfig = $this->numberConfig();

        return $data;
    }
}
