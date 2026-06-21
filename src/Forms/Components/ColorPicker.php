<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

class ColorPicker extends Field
{
    protected function getType(): string
    {
        return 'color-picker';
    }
}
