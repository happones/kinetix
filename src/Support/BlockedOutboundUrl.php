<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use RuntimeException;

/**
 * An outbound request (or one of its redirects) was refused by the SSRF guard
 * ({@see OutboundHttp}).
 *
 * @internal
 */
final class BlockedOutboundUrl extends RuntimeException
{
    public static function for(string $url): self
    {
        $host = parse_url($url, PHP_URL_HOST);

        return new self('Blocked: '.(is_string($host) && $host !== '' ? $host : 'the URL').' failed SSRF validation.');
    }
}
