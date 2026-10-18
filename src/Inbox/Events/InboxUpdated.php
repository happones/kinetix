<?php

declare(strict_types=1);

namespace Happones\Kinetix\Inbox\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A conversation changed — a message arrived, was sent, delivered or read, it
 * was assigned. Broadcast on its line's private channel with ids only: the
 * inbox fetches what changed through its authorized endpoints, so no message
 * content travels over the socket.
 */
class InboxUpdated implements ShouldBroadcastNow
{
    /**
     * @param array{conversationId: int|string, channelId: int|string, messageId: int|string|null} $payload
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
        return 'inbox.updated';
    }

    /**
     * @return array{conversationId: int|string, channelId: int|string, messageId: int|string|null}
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
