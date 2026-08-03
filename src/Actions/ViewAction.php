<?php

declare(strict_types=1);

namespace Happones\Kinetix\Actions;

class ViewAction extends Action
{
    public static function make(string $name = 'view'): static
    {
        return new static($name);
    }

    public function __construct(string $name = 'view')
    {
        parent::__construct($name);

        $this->label((string) __('kinetix.view'))
            ->icon('eye')
            ->color('gray')
            ->authorize('view'); // checks the model's `view` policy per record
    }
}
