<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting;

use Happones\Kinetix\Notifications\KinetixLaravelNotification;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification as BaseLaravelNotification;

/**
 * The database channel for a routed delivery: the stored row carries the team
 * the delivery ran for, so a notification a host class sends to someone in
 * two teams shows only in the bell of the team it was routed in. A row whose
 * data already names a team keeps it.
 *
 * @internal
 */
class TeamStampedDatabaseChannel extends DatabaseChannel
{
    /**
     * @param  mixed                $notifiable
     * @return array<string, mixed>
     */
    protected function buildPayload($notifiable, BaseLaravelNotification $notification): array
    {
        $payload = parent::buildPayload($notifiable, $notification);
        $team    = RoutedDelivery::team();

        if ($team !== null && is_array($payload['data'] ?? null)) {
            $payload['data']['team'] ??= $team;
        }

        if ($notification instanceof KinetixLaravelNotification && $notification->markAsRead) {
            $payload['read_at'] = now();
        }

        return $payload;
    }
}
