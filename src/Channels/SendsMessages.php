<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels;

/**
 * A driver that sends rich messages — a file, a reply to a message — and
 * returns the provider's id for what it sent, so its delivery status can
 * follow. What the inbox sends through.
 */
interface SendsMessages
{
    /** Send it and return the provider's id for it (null when it gives none). */
    public function send(Channel $channel, OutgoingMessage $message): ?string;
}
