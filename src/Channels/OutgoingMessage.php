<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels;

/**
 * A message a line sends: text, and/or a file (its contents, MIME type and
 * name; `mediaType` image, video, audio or document), optionally answering a
 * message (`replyTo`, the provider's id for it).
 */
final class OutgoingMessage
{
    public function __construct(
        public readonly string $to,
        public readonly ?string $text = null,
        public readonly ?string $mediaContents = null,
        public readonly ?string $mediaMime = null,
        public readonly ?string $mediaName = null,
        public readonly ?string $mediaType = null,
        public readonly ?string $replyTo = null,
    ) {}

    public function hasMedia(): bool
    {
        return $this->mediaContents !== null;
    }
}
