<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels\Events;

use Happones\Kinetix\Channels\Channel;
use Illuminate\Foundation\Events\Dispatchable;

/** The provider issued a new QR for a line waiting to be scanned (a data URI). */
class ChannelQrUpdated
{
    use Dispatchable;

    public function __construct(
        public readonly Channel $channel,
        public readonly string $qr,
    ) {}
}
