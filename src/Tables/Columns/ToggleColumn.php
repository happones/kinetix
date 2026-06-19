<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Columns;

class ToggleColumn extends Column
{
    protected function getType(): string
    {
        return 'toggle-input';
    }
}
