<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels;

/**
 * What a provider's webhook call carried, normalized: a status change (with
 * the linked number or username), a fresh QR, inbound messages, and the
 * delivery status of messages the line sent (`statuses`: the provider's
 * message id → `sent`, `delivered`, `read` or `failed`).
 */
final class WebhookResult
{
    /**
     * @param list<InboundMessage>  $messages
     * @param array<string, string> $statuses
     */
    public function __construct(
        public readonly ?ChannelStatus $status = null,
        public readonly ?string $handle = null,
        public readonly ?string $qr = null,
        public readonly array $messages = [],
        public readonly array $statuses = [],
    ) {}

    public static function none(): self
    {
        return new self;
    }
}
