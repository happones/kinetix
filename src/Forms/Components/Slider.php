<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Illuminate\Database\Eloquent\Model;

/**
 * A range slider (Reka UI Slider). Stores a single number between min and max.
 *
 *     Slider::make('volume')->min(0)->max(100)->step(5);
 */
class Slider extends Field
{
    protected float $min = 0.0;

    protected float $max = 100.0;

    protected float $step = 1.0;

    protected function getType(): string
    {
        return 'slider';
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

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        $data = parent::toData($operation, $record);

        if ($data === null) {
            return null;
        }

        $data->numberConfig = [
            'min'      => $this->min,
            'max'      => $this->max,
            'step'     => $this->step,
            'format'   => 'decimal',
            'currency' => null,
            'decimals' => null,
            'locale'   => null,
        ];

        return $data;
    }
}
