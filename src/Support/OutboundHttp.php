<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Happones\Kinetix\Webhooks\WebhookUrlGuard;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Sends a request to a customer-supplied URL without letting it reach an
 * address the SSRF guard refuses ({@see WebhookUrlGuard}).
 *
 * - The connection is pinned (CURLOPT_RESOLVE) to the address that was
 *   validated, so the name can't resolve somewhere else between the check and
 *   the connect (DNS rebinding). An IP-literal URL needs no pin; with
 *   `kinetix.webhooks.allow_private` on nothing is pinned.
 * - Redirects are never followed by the HTTP client. With `$maxRedirects`
 *   above 0 they are followed here, each Location validated and pinned again;
 *   307/308 keep the method and body, 301/302/303 continue as a GET. Once a
 *   hop leaves the original origin (scheme, host or port), every header the
 *   caller set is dropped except Accept, Content-Type and User-Agent — no
 *   credentials (Authorization, Cookie, a node's own headers) follow a
 *   redirect to another site.
 * - Without ext-curl a name can't be pinned, so the request is refused.
 *
 * With an HTTP(S)_PROXY configured the proxy makes the connection, so
 * egress filtering is the proxy's job.
 *
 * @internal
 */
final class OutboundHttp
{
    /**
     * The headers a request keeps on a hop to another origin.
     *
     * @var list<string>
     */
    private const CROSS_ORIGIN_HEADERS = ['accept', 'content-type', 'user-agent', 'host', 'content-length', 'transfer-encoding'];

    /**
     * The request is consumed: a cross-origin hop adds a header-stripping
     * middleware to it.
     *
     * @param array<string, mixed> $options Guzzle request options (json, body…)
     *
     * @throws BlockedOutboundUrl when the URL, or a redirect it leads to, is refused
     */
    public static function send(PendingRequest $request, string $method, string $url, array $options, int $maxRedirects = 0): Response
    {
        $hops     = 0;
        $withBody = true;
        $origin   = self::origin($url);
        $stripped = false;

        while (true) {
            $response = self::sendOnce($request, $method, $url, $options, $withBody);

            if (! $response->redirect() || $hops >= $maxRedirects) {
                return $response;
            }

            $location = $response->header('Location');

            if ($location === '') {
                return $response;
            }

            $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));

            if (! $stripped && self::origin($url) !== $origin) {
                $request->withRequestMiddleware(self::stripCallerHeaders(...));
                $stripped = true;
            }

            if (in_array($response->status(), [301, 302, 303], true)) {
                $method   = 'GET';
                $withBody = false;
                $options  = array_diff_key($options, array_flip(['body', 'json', 'form_params', 'multipart']));
            }

            $hops++;
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function sendOnce(PendingRequest $request, string $method, string $url, array $options, bool $withBody): Response
    {
        $target = WebhookUrlGuard::target($url);

        if ($target === null) {
            throw BlockedOutboundUrl::for($url);
        }

        $pending = (clone $request)->withoutRedirecting();
        $pin     = self::pin($target);

        // A body set with withBody() lives on the request, not in $options.
        if (! $withBody) {
            $pending->withBody('');
        }

        if ($pin !== null) {
            $pending->withOptions(['curl' => [CURLOPT_RESOLVE => [$pin]]]);
        }

        return $pending->send($method, $url, $options);
    }

    /**
     * `scheme://host:port`, with the scheme's default port filled in.
     */
    private static function origin(string $url): string
    {
        $uri    = new Uri($url);
        $scheme = strtolower($uri->getScheme());
        $port   = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);

        return $scheme.'://'.strtolower($uri->getHost()).':'.$port;
    }

    /**
     * Drop every header but the content-negotiation ones.
     */
    private static function stripCallerHeaders(RequestInterface $request): RequestInterface
    {
        foreach (array_keys($request->getHeaders()) as $header) {
            if (! in_array(strtolower((string) $header), self::CROSS_ORIGIN_HEADERS, true)) {
                $request = $request->withoutHeader((string) $header);
            }
        }

        return $request;
    }

    /**
     * The CURLOPT_RESOLVE entry pinning the host to its first validated
     * address — null when there is nothing to pin (an IP literal, or private
     * hosts allowed).
     *
     * @param array{host: string, port: int, ips: list<string>} $target
     *
     * @throws BlockedOutboundUrl when a name must be pinned but curl is missing
     */
    public static function pin(array $target): ?string
    {
        if ($target['ips'] === [] || filter_var($target['host'], FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        if (! defined('CURLOPT_RESOLVE')) {
            throw new BlockedOutboundUrl('Blocked: ext-curl is required to pin outbound connections.');
        }

        $ip = $target['ips'][0];

        return "{$target['host']}:{$target['port']}:".(str_contains($ip, ':') ? "[{$ip}]" : $ip);
    }
}
