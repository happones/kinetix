<?php

declare(strict_types=1);

namespace Happones\Kinetix\Infolists\Components;

use Happones\Kinetix\Support\Contracts\HasColor;
use Illuminate\Database\Eloquent\Model;

class ColorEntry extends Entry
{
    protected bool $isCopyable = false;

    protected function getType(): string
    {
        return 'color';
    }

    public function copyable(bool $condition = true): static
    {
        $this->isCopyable = $condition;

        return $this;
    }

    public function getState(?Model $record = null): mixed
    {
        $value = parent::getState($record);

        if ($value === null) {
            return null;
        }

        if ($value instanceof HasColor || (is_object($value) && method_exists($value, 'getColor'))) {
            return $value->getColor();
        }

        return $this->resolveEnumState($value);
    }

    protected function getExtraData(?Model $record = null): array
    {
        return [
            'isCopyable' => $this->isCopyable,
        ];
    }
}
