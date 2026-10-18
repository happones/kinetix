<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels;

/**
 * A driver that can tell a linked line's number or username on demand — for
 * a line that connected without a webhook carrying it (linked by polling).
 */
interface ReportsHandle
{
    public function handle(Channel $channel): ?string;
}
