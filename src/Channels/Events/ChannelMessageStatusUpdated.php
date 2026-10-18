<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels\Events;

use Happones\Kinetix\Channels\Channel;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The provider reported where a message the line sent stands: `sent`,
 * `delivered`, `read` or `failed` (`externalId` is the provider's id for it).
 */
class ChannelMessageStatusUpdated
{
    use Dispatchable;

    public function __construct(
        public readonly Channel $channel,
        public readonly string $externalId,
        public readonly string $status,
    ) {}
}
