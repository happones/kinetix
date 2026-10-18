<?php

declare(strict_types=1);

namespace Happones\Kinetix\Inbox;

use Happones\Kinetix\Channels\Events\ChannelMessageReceived;
use Happones\Kinetix\Channels\Events\ChannelMessageStatusUpdated;

/** Wires the inbox to its lines: what arrives is recorded, and receipts move sent messages along. */
class RecordInboxActivity
{
    public function __construct(protected InboxManager $inbox) {}

    public function received(ChannelMessageReceived $event): void
    {
        $this->inbox->recordInbound($event->channel, $event->message);
    }

    public function statusUpdated(ChannelMessageStatusUpdated $event): void
    {
        $this->inbox->recordStatus($event->channel, $event->externalId, $event->status);
    }
}
