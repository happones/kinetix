<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

class Checkbox extends Field
{
    protected function getType(): string
    {
        return 'checkbox';
    }
}
