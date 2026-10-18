<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels\Events;

use Happones\Kinetix\Channels\Channel;
use Happones\Kinetix\Channels\ChannelStatus;
use Illuminate\Foundation\Events\Dispatchable;

/** A line connected, disconnected or failed (its handle filled once linked). */
class ChannelStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Channel $channel,
        public readonly ChannelStatus $from,
        public readonly ChannelStatus $to,
    ) {}
}
