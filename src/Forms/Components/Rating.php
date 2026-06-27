<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Illuminate\Database\Eloquent\Model;

/**
 * A star rating field. Stores a number from 0 to max (halves allowed when
 * allowHalf() is set).
 *
 *     Rating::make('score')->max(5);
 *     Rating::make('score')->max(10)->allowHalf();
 */
class Rating extends Field
{
    protected int $max = 5;

    protected bool $allowHalf = false;

    protected function getType(): string
    {
        return 'rating';
    }

    public function max(int $max): static
    {
        $this->max = max(1, $max);

        return $this;
    }

    public function allowHalf(bool $allowHalf = true): static
    {
        $this->allowHalf = $allowHalf;

        return $this;
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        $data = parent::toData($operation, $record);

        if ($data === null) {
            return null;
        }

        $data->ratingConfig = [
            'max'       => $this->max,
            'allowHalf' => $this->allowHalf,
        ];

        return $data;
    }
}
