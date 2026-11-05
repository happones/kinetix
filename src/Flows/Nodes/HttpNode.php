<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows\Nodes;

use Happones\Kinetix\Flows\FlowStep;
use Happones\Kinetix\Flows\NodeOutcome;
use Happones\Kinetix\Support\OutboundHttp;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Calls your API: `method`, `url`, `headers` and a JSON `body` (all with
 * `{{variables}}`), then keeps parts of the response — `save` ({variable,
 * path}, a dot path into the JSON) — and `http.status`. A refused URL (a
 * private address, or a redirect to one), a failure or a non-2xx answer
 * follows `error`. The call is pinned to the validated address and follows
 * redirects only up to `kinetix.flows.follow_redirects` ({@see OutboundHttp});
 * the Host, Content-Length, Transfer-Encoding, Connection and Proxy-* headers
 * are never sent from the node's list.
 */
class HttpNode extends BaseNode
{
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Headers dropped from a node's own list (any `Proxy-*` header too).
     *
     * @var list<string>
     */
    private const RESERVED_HEADERS = ['host', 'content-length', 'transfer-encoding', 'connection'];

    public function outputs(array $data): array
    {
        return ['next', 'error'];
    }

    public function execute(FlowStep $step): NodeOutcome
    {
        if ($step->simulation && ! config('kinetix.flows.simulate_http', true)) {
            return NodeOutcome::next();
        }

        $url    = $step->render((string) ($step->data['url'] ?? ''));
        $method = strtoupper((string) ($step->data['method'] ?? 'GET'));

        if (! in_array($method, self::METHODS, true)) {
            return NodeOutcome::next('error');
        }

        $headers = [];

        foreach ((array) ($step->data['headers'] ?? []) as $header) {
            $name = is_array($header) ? trim((string) ($header['key'] ?? '')) : '';

            if ($name !== '' && ! self::isReservedHeader($name)) {
                $headers[$name] = $step->render((string) ($header['value'] ?? ''));
            }
        }

        try {
            $request = Http::timeout((int) config('kinetix.flows.http_timeout', 10))->acceptJson()->withHeaders($headers);
            $body    = trim($step->render((string) ($step->data['body'] ?? '')));
            $payload = $body !== '' ? json_decode($body, true) : null;

            $options = $method === 'GET'
                ? []
                : (is_array($payload) ? ['json' => $payload] : ($body !== '' ? ['body' => $body] : []));

            $response = OutboundHttp::send($request, $method, $url, $options, max(0, (int) config('kinetix.flows.follow_redirects', 0)));
        } catch (Throwable) {
            return NodeOutcome::next('error');
        }

        $step->setVariable('http.status', $response->status());

        if (! $response->successful()) {
            return NodeOutcome::next('error');
        }

        $json = $response->json();

        foreach ((array) ($step->data['save'] ?? []) as $mapping) {
            if (is_array($mapping) && trim((string) ($mapping['variable'] ?? '')) !== '') {
                $path = trim((string) ($mapping['path'] ?? ''));
                $step->setVariable((string) $mapping['variable'], $path === '' ? $json : data_get($json, $path));
            }
        }

        return NodeOutcome::next();
    }

    /**
     * Headers a flow may not set: they would let it address another virtual
     * host behind the same IP, smuggle a request or talk to a proxy.
     */
    private static function isReservedHeader(string $name): bool
    {
        $name = strtolower($name);

        return in_array($name, self::RESERVED_HEADERS, true) || str_starts_with($name, 'proxy-');
    }

    public function problem(array $data): ?string
    {
        return trim((string) ($data['url'] ?? '')) === '' ? $this->missing('url') : null;
    }
}
