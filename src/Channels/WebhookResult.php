<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels;

/**
 * What a provider's webhook call carried, normalized: a status change (with
 * the linked number or username), a fresh QR, inbound messages, messages the
 * line itself sent (`sent`: from its phone, from a bot the provider runs, or
 * Kinetix's own sends coming back — `from` is the contact), and the delivery
 * status of messages the line sent (`statuses`: the provider's message id →
 * `sent`, `delivered`, `read` or `failed`), and messages deleted for
 * everyone (`deleted`: the provider's ids).
 */
final class WebhookResult
{
    /**
     * @param list<InboundMessage>  $messages
     * @param array<string, string> $statuses
     * @param list<InboundMessage>  $sent
     * @param list<string>          $deleted  The provider's ids of messages deleted for everyone.
     */
    public function __construct(
        public readonly ?ChannelStatus $status = null,
        public readonly ?string $handle = null,
        public readonly ?string $qr = null,
        public readonly array $messages = [],
        public readonly array $statuses = [],
        public readonly array $sent = [],
        public readonly array $deleted = [],
    ) {}

    public static function none(): self
    {
        return new self;
    }
}
