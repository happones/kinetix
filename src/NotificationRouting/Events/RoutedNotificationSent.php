<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * One chunk of a routed notification was delivered.
 */
class RoutedNotificationSent
{
    use Dispatchable;

    /**
     * @param list<string> $channels
     */
    public function __construct(
        public readonly string $type,
        public readonly int|string|null $team,
        public readonly array $channels,
        public readonly int $sent,
        public readonly int $failed,
    ) {}
}
