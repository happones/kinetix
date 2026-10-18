<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Broadcast when `kinetix.channels.broadcast` is on: a line's status or QR
 * changed, so an open linker updates at once instead of on its next poll.
 */
class ChannelUpdated implements ShouldBroadcastNow
{
    /**
     * @param array{id: int|string, status: string, handle: string|null, qr: string|null} $payload
     */
    public function __construct(
        public readonly string $channelName,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->channelName);
    }

    public function broadcastAs(): string
    {
        return 'channel.updated';
    }

    /**
     * @return array{id: int|string, status: string, handle: string|null, qr: string|null}
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
