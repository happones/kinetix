<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels;

/** A driver that fetches the file of an inbound message by its `mediaRef`. */
interface DownloadsMedia
{
    public function downloadMedia(Channel $channel, string $mediaRef): ?DownloadedMedia;
}
