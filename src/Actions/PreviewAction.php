<?php

declare(strict_types=1);

namespace Happones\Kinetix\Actions;

class PreviewAction extends Action
{
    public static function make(string $name = 'preview'): static
    {
        return new static($name);
    }

    public function __construct(string $name = 'preview')
    {
        parent::__construct($name);

        $this->label((string) __('kinetix.preview'))
            ->icon('eye')
            ->color('gray')
            ->preview();
    }
}
