<?php

declare(strict_types=1);

namespace Happones\Kinetix\Actions;

class DeleteAction extends Action
{
    public static function make(string $name = 'delete'): static
    {
        return new static($name);
    }

    public function __construct(string $name = 'delete')
    {
        parent::__construct($name);

        $this->label((string) trans('kinetix.delete'))
            ->icon('trash')
            ->color('danger')
            ->requiresConfirmation()
            ->authorize('delete'); // checks the model's `delete` policy per record
    }
}
