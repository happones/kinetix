<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

class TagsInput extends Field
{
    protected function getType(): string
    {
        return 'tags-input';
    }
}
