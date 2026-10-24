<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels\Events;

use Happones\Kinetix\Channels\Channel;
use Illuminate\Foundation\Events\Dispatchable;

/** A message on the line was deleted for everyone (by the contact, or from the line's phone). */
class ChannelMessageDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly Channel $channel,
        public readonly string $externalId,
    ) {}
}
