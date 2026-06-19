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

    public function copyable(bool $condition = true): static
    {
        $this->isCopyable = $condition;

        return $this;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'isCopyable' => $this->isCopyable,
        ]);
    }
}
