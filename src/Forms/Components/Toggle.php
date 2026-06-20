<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

class Toggle extends Field
{
    protected function getType(): string
    {
        return 'toggle';
    }
}
