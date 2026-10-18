<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels;

/** A file a driver fetched from its provider: its bytes, MIME type and name. */
final class DownloadedMedia
{
    public function __construct(
        public readonly string $contents,
        public readonly ?string $mime = null,
        public readonly ?string $name = null,
    ) {}
}
