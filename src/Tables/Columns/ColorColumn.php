<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Columns;

use Happones\Kinetix\Support\Contracts\HasColor;
use Illuminate\Database\Eloquent\Model;

class ColorColumn extends Column
{
    protected function getType(): string
    {
        return 'color';
    }

    public function getState(Model $record): mixed
    {
        $value = parent::getState($record);

        if ($value === null) {
            return null;
        }

        if ($value instanceof HasColor || (is_object($value) && method_exists($value, 'getColor'))) {
            return $value->getColor();
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        return $value;
    }
}
