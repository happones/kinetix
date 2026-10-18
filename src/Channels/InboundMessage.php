<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels;

use Illuminate\Support\Carbon;

/**
 * A message that reached a line, the same shape for every provider: who sent
 * it (`from` is what `sendText()` takes to reply), what it says, the message
 * it answers (`replyTo`, the provider's id for it), the reference a driver
 * downloads its media by (`mediaRef`), and the provider's raw payload for
 * anything Kinetix doesn't normalize.
 */
final class InboundMessage
{
    /**
     * @param string               $type text, image, video, audio, document, sticker, location, contact, other
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $from,
        public readonly ?string $fromName,
        public readonly ?string $text,
        public readonly string $type,
        public readonly ?string $externalId,
        public readonly bool $isGroup,
        public readonly Carbon $receivedAt,
        public readonly array $raw = [],
        public readonly ?string $replyTo = null,
        public readonly ?string $mediaRef = null,
        public readonly ?string $mediaMime = null,
        public readonly ?string $mediaName = null,
    ) {}

    public function hasMedia(): bool
    {
        return $this->mediaRef !== null && $this->mediaRef !== '';
    }
}
