<?php

declare(strict_types=1);

namespace Happones\Kinetix\Actions;

class CreateAction extends Action
{
    public static function make(string $name = 'create'): static
    {
        return new static($name);
    }

    public function __construct(string $name = 'create')
    {
        parent::__construct($name);

        $this->label((string) __('kinetix.create'))
            ->icon('plus')
            ->color('primary');

        // A create action has no record to authorize against. To enforce the
        // policy, pass the model class explicitly:
        //   CreateAction::make()->authorize('create', Post::class)
    }
}
