<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels\Events;

use Happones\Kinetix\Channels\Channel;
use Happones\Kinetix\Channels\InboundMessage;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A message reached a line — the hook for an inbox, a bot or a flow. Reply
 * with `KinetixChannels::sendText($event->channel, $event->message->from, …)`.
 */
class ChannelMessageReceived
{
    use Dispatchable;

    public function __construct(
        public readonly Channel $channel,
        public readonly InboundMessage $message,
    ) {}
}
