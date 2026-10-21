<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels\Events;

use Happones\Kinetix\Channels\Channel;
use Happones\Kinetix\Channels\InboundMessage;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The line itself sent a message: from its phone, from a bot the provider
 * runs on it, or one of Kinetix's own sends reported back. `$message->from`
 * is the contact it went to. The inbox records the ones it didn't send.
 */
class ChannelMessageSent
{
    use Dispatchable;

    public function __construct(
        public readonly Channel $channel,
        public readonly InboundMessage $message,
    ) {}
}
