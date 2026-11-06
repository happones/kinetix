<?php

declare(strict_types=1);

namespace Happones\Kinetix\Resources;

/**
 * The write a resource's CRUD message reports. See
 * {@see Resource::getCrudNotification()}.
 */
enum CrudEvent: string
{
    case Created      = 'created';
    case Updated      = 'updated';
    case Deleted      = 'deleted';
    case Restored     = 'restored';
    case ForceDeleted = 'force_deleted';

    /**
     * The translation key of the default message: `kinetix.record_{value}`.
     */
    public function messageKey(): string
    {
        return 'kinetix.record_'.$this->value;
    }
}
