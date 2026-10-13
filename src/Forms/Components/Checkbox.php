<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Forms\Components\Concerns\HoldsOnOrOff;

class Checkbox extends Field
{
    use HoldsOnOrOff;

    protected function getType(): string
    {
        return 'checkbox';
    }
}
