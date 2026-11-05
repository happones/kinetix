<?php

declare(strict_types=1);

namespace Happones\Kinetix\Webhooks;

use Happones\Kinetix\Support\OutboundHttp;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * SSRF guard for customer-supplied outbound URLs (webhook endpoints, flow HTTP
 * nodes). Rejects non-HTTP(S) schemes and any host with an address in a
 * private, loopback, link-local, carrier-grade NAT, documentation, multicast
 * or otherwise special-use range — IPv4 and IPv6, every A and AAAA record —
 * so a customer can't point a request at your internal network or a cloud
 * metadata endpoint.
 *
 * Validating a name is only half the job: the request must then connect to
 * the address that was validated, not resolve the name again (DNS rebinding).
 * {@see OutboundHttp} pins the connection to
 * {@see target()}'s addresses.
 *
 * Set `kinetix.webhooks.allow_private` to permit private hosts in
 * local/testing environments.
 */
class WebhookUrlGuard
{
    /**
     * Special-use IPv4 ranges no outbound request may reach.
     *
     * @var list<string>
     */
    public const BLOCKED_V4 = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    /**
     * Special-use IPv6 ranges no outbound request may reach (IPv4-mapped and
     * translated addresses included, so `::ffff:127.0.0.1` is loopback too).
     *
     * @var list<string>
     */
    public const BLOCKED_V6 = [
        '::/96',
        '::/128',
        '::1/128',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001::/32',
        '2001:db8::/32',
        '2002::/16',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];

    public static function isAllowed(string $url): bool
    {
        return static::target($url) !== null;
    }

    /**
     * Where a request to `$url` may connect: its host, port and the validated
     * addresses — null when the URL may not be called at all. With
     * `allow_private` on, nothing is resolved and `ips` is empty (no pin).
     *
     * @return array{host: string, port: int, ips: list<string>}|null
     */
    public static function target(string $url): ?array
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }

        $host = trim((string) ($parts['host'] ?? ''), '[]');

        if ($host === '') {
            return null;
        }

        $port = (int) ($parts['port'] ?? (strtolower((string) $parts['scheme']) === 'https' ? 443 : 80));

        if (config('kinetix.webhooks.allow_private', false)) {
            return ['host' => $host, 'port' => $port, 'ips' => []];
        }

        $ips = static::resolve($host);

        if ($ips === []) {
            return null; // unresolvable → reject
        }

        foreach ($ips as $ip) {
            if (static::isBlockedIp($ip)) {
                return null;
            }
        }

        return ['host' => $host, 'port' => $port, 'ips' => $ips];
    }

    /**
     * Whether an address sits in a special-use range (or isn't an address).
     */
    public static function isBlockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        return IpUtils::checkIp($ip, str_contains($ip, ':') ? self::BLOCKED_V6 : self::BLOCKED_V4);
    }

    /**
     * @return list<string>
     */
    protected static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return app(HostResolver::class)->resolve($host);
    }
}
