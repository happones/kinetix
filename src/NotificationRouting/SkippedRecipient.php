<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting;

use Illuminate\Database\Eloquent\Model;

/**
 * A team member a route reached who is left out, and why.
 */
final class SkippedRecipient
{
    public function __construct(
        public readonly Model $user,
        public readonly SkipReason $reason,
    ) {}
}
