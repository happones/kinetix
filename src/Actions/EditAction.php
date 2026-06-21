<?php

declare(strict_types=1);

namespace Happones\Kinetix\Actions;

class EditAction extends Action
{
    public static function make(string $name = 'edit'): static
    {
        return new static($name);
    }

    public function __construct(string $name = 'edit')
    {
        parent::__construct($name);

        $this->label((string) trans('kinetix.edit'))
            ->icon('edit')
            ->color('gray')
            ->authorize('update'); // checks the model's `update` policy per record
    }
}
