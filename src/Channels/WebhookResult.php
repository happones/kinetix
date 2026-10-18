<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels;

/**
 * What a provider's webhook call carried, normalized: a status change (with
 * the linked number or username), a fresh QR, and inbound messages.
 */
final class WebhookResult
{
    /**
     * @param list<InboundMessage> $messages
     */
    public function __construct(
        public readonly ?ChannelStatus $status = null,
        public readonly ?string $handle = null,
        public readonly ?string $qr = null,
        public readonly array $messages = [],
    ) {}

    public static function none(): self
    {
        return new self;
    }
}
