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

        // The confirmation names the action on its heading and its button, so a
        // delete never asks a bare "Are you sure?" answered by "Confirm". No
        // default description: whether it can be undone depends on the model
        // (soft deletes), so that sentence is the app's to add.
        $this->label((string) __('kinetix.delete'))
            ->icon('trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading((string) __('kinetix.confirm_delete'))
            ->modalSubmitActionLabel((string) __('kinetix.delete'))
            ->authorize('delete'); // checks the model's `delete` policy per record
    }
}
