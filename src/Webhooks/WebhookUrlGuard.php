<?php

declare(strict_types=1);

namespace Happones\Kinetix\Webhooks;

/**
 * SSRF guard for customer-supplied webhook URLs. Rejects non-HTTP(S) schemes and
 * any host that resolves to a private, loopback, link-local or reserved IP — so
 * a customer can't point a webhook at your internal network or cloud metadata
 * endpoint. Set `kinetix.webhooks.allow_private` to permit private hosts in
 * local/testing environments.
 */
class WebhookUrlGuard
{
    public static function isAllowed(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return false;
        }

        $host = $parts['host'] ?? '';

        if ($host === '') {
            return false;
        }

        if (config('kinetix.webhooks.allow_private', false)) {
            return true;
        }

        $ips = static::resolve($host);

        if ($ips === []) {
            return false; // unresolvable → reject
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE) === false) {
                return false; // private / reserved / loopback / link-local
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    protected static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = gethostbynamel($host);

        return $ips === false ? [] : $ips;
    }
}
