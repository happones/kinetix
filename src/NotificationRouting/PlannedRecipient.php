<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting;

use Illuminate\Database\Eloquent\Model;

/**
 * One team member a notification is sent to, with the channels it goes out on.
 */
final class PlannedRecipient
{
    /**
     * @param list<string> $channels
     */
    public function __construct(
        public readonly Model $user,
        public readonly array $channels,
    ) {}
}
