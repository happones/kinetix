<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting\Jobs;

use Happones\Kinetix\NotificationRouting\Events\RoutedNotificationSent;
use Happones\Kinetix\NotificationRouting\RoutedDelivery;
use Happones\Kinetix\NotificationRouting\TeamStampedDatabaseChannel;
use Happones\Kinetix\NotificationRouting\UserRefs;
use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends a routed notification to one chunk of people, all on the same
 * channels. Never retried: a retry after a partial send would notify the
 * people already reached twice. One person's failure is reported and does not
 * stop the others.
 */
class DeliverRouted implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    /**
     * @param list<array{0: string, 1: int|string}> $users
     * @param non-empty-list<string>                $channels
     */
    public function __construct(
        public readonly string $type,
        public readonly int|string|null $teamKey,
        public readonly object $notification,
        public readonly array $users,
        public readonly array $channels,
    ) {
        $queue = config('kinetix.notification_routing.queue');

        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function handle(): void
    {
        $via    = array_map(static fn (string $channel): string => $channel === 'database' ? TeamStampedDatabaseChannel::class : $channel, $this->channels);
        $stamp  = $this->teamKey !== null && KinetixTeams::enabledFor('notifications') ? $this->teamKey : null;
        $sent   = 0;
        $failed = 0;

        RoutedDelivery::within($stamp, function () use ($via, &$sent, &$failed): void {
            foreach (UserRefs::load($this->users) as $user) {
                try {
                    Notification::sendNow($user, $this->notification, $via);
                    $sent++;
                } catch (Throwable $e) {
                    report($e);
                    $failed++;
                }
            }
        });

        event(new RoutedNotificationSent($this->type, $this->teamKey, $this->channels, $sent, $failed));
    }
}
