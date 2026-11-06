<?php

declare(strict_types=1);

namespace Happones\Kinetix\Resources;

/**
 * How a resource's CRUD message reaches the user. See
 * {@see Resource::getCrudNotificationDelivery()}.
 */
enum CrudNotificationDelivery: string
{
    /** A one-shot toast on the next page. The default. */
    case Toast = 'toast';

    /** Persisted unread for the bell (and broadcast when enabled); no toast. */
    case Database = 'database';

    /** A toast now, plus a row that is already read, kept as history. Never broadcast. */
    case ToastAndDatabase = 'toast_and_database';

    public function toasts(): bool
    {
        return $this !== self::Database;
    }

    public function persists(): bool
    {
        return $this !== self::Toast;
    }
}
