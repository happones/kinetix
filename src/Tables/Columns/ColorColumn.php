<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Columns;

class ColorColumn extends Column
{
    protected bool $isCopyable = false;

    protected function getType(): string
    {
        return 'color';
    }

    public function getState(\Illuminate\Database\Eloquent\Model $record): mixed
    {
        $value = parent::getState($record);

        if ($value === null) {
            return null;
        }

        if ($value instanceof \Happones\Kinetix\Support\Contracts\HasColor) {
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

    public function copyable(bool $condition = true): static
    {
        $this->isCopyable = $condition;

        return $this;
    }

    protected function getExtraData(): array
    {
        return [
            'isCopyable' => $this->isCopyable,
        ];
    }
}
