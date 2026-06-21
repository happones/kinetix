<?php

declare(strict_types=1);

namespace Happones\Kinetix\Actions;

use Illuminate\Database\Eloquent\Model;

class ForceDeleteAction extends Action
{
    public static function make(string $name = 'forceDelete'): static
    {
        return new static($name);
    }

    public function __construct(string $name = 'forceDelete')
    {
        parent::__construct($name);

        $this->label((string) trans('kinetix.force_delete'))
            ->icon('trash-2')
            ->color('danger')
            ->requiresConfirmation()
            ->authorize('forceDelete') // checks the model's `forceDelete` policy per record
            ->visible(fn (?Model $record) => $record !== null
                && method_exists($record, 'trashed')
                && $record->trashed());
    }
}
