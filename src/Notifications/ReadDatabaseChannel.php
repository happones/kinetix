<?php

declare(strict_types=1);

namespace Happones\Kinetix\Notifications;

use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification as BaseLaravelNotification;

/**
 * The database channel, storing the row already read. Laravel's own
 * `initialDatabaseReadAtValue()` hook is missing from the 11.0 floor (it
 * arrives later in 11.x), so this works on every supported framework version.
 *
 * @internal
 */
class ReadDatabaseChannel extends DatabaseChannel
{
    /**
     * @param  mixed                $notifiable
     * @return array<string, mixed>
     */
    protected function buildPayload($notifiable, BaseLaravelNotification $notification): array
    {
        return array_merge(parent::buildPayload($notifiable, $notification), ['read_at' => now()]);
    }
}
