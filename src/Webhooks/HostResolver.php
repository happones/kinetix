<?php

declare(strict_types=1);

namespace Happones\Kinetix\Webhooks;

/**
 * Resolves a host name to every address it points at — IPv4 AND IPv6 — for
 * the outbound SSRF guard ({@see WebhookUrlGuard}).
 *
 * Bound in the container, so a test (or an app with its own resolver) can
 * swap it.
 */
class HostResolver
{
    /**
     * Every A and AAAA address of `$host`, plus what the system resolver
     * answers (the hosts file). Empty when the name doesn't resolve.
     *
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $ips = [];

        $records = @dns_get_record($host, DNS_A|DNS_AAAA);

        foreach (is_array($records) ? $records : [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip) && $ip !== '') {
                $ips[] = $ip;
            }
        }

        $system = @gethostbynamel($host);

        foreach (is_array($system) ? $system : [] as $ip) {
            $ips[] = $ip;
        }

        return array_values(array_unique($ips));
    }
}
