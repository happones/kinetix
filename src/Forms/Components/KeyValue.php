<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

class KeyValue extends Field
{
    protected function getType(): string
    {
        return 'key-value';
    }
}
