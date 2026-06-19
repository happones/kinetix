<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Columns;

class CheckboxColumn extends Column
{
    protected function getType(): string
    {
        return 'checkbox-input';
    }
}
