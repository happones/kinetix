<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels;

/**
 * A message a line sends: text, and/or a file (its contents, MIME type and
 * name; `mediaType` image, video, audio or document), optionally answering a
 * message (`replyTo`, the provider's id for it) and offering `options` to
 * pick — buttons where the provider has them, else a numbered menu
 * (`textWithOptions()`).
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
        /** @var list<string> */
        public readonly array $options = [],
    ) {}

    /** The text with the options as a numbered menu, for providers without buttons. */
    public function textWithOptions(): string
    {
        if ($this->options === []) {
            return (string) $this->text;
        }

        $menu = array_map(static fn (string $option, int $index): string => ($index + 1).'. '.$option, $this->options, array_keys($this->options));

        return trim((string) $this->text."\n\n".implode("\n", $menu));
    }

    public function hasMedia(): bool
    {
        return $this->mediaContents !== null;
    }
}
