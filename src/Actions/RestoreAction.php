<?php

declare(strict_types=1);

namespace Happones\Kinetix\Actions;

use Illuminate\Database\Eloquent\Model;

class RestoreAction extends Action
{
    public static function make(string $name = 'restore'): static
    {
        return new static($name);
    }

    public function __construct(string $name = 'restore')
    {
        parent::__construct($name);

        $this->label((string) __('kinetix.restore'))
            ->icon('rotate-ccw')
            ->color('gray')
            ->authorize('restore') // checks the model's `restore` policy per record
            ->visible(fn (?Model $record) => $record !== null
                && method_exists($record, 'trashed')
                && $record->trashed());
    }
}
