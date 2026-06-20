<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

class DateTimePicker extends Field
{
    protected function getType(): string
    {
        return 'datetime-picker';
    }
}
