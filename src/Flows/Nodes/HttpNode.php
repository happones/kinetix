<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows\Nodes;

use Happones\Kinetix\Flows\FlowStep;
use Happones\Kinetix\Flows\NodeOutcome;
use Happones\Kinetix\Webhooks\WebhookUrlGuard;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Calls your API: `method`, `url`, `headers` and a JSON `body` (all with
 * `{{variables}}`), then keeps parts of the response — `save` ({variable,
 * path}, a dot path into the JSON) — and `http.status`. A refused URL (a
 * private address), a failure or a non-2xx answer follows `error`.
 */
class HttpNode extends BaseNode
{
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

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

        if (! in_array($method, self::METHODS, true) || ! WebhookUrlGuard::isAllowed($url)) {
            return NodeOutcome::next('error');
        }

        $headers = [];

        foreach ((array) ($step->data['headers'] ?? []) as $header) {
            if (is_array($header) && trim((string) ($header['key'] ?? '')) !== '') {
                $headers[trim((string) $header['key'])] = $step->render((string) ($header['value'] ?? ''));
            }
        }

        try {
            $request = Http::timeout((int) config('kinetix.flows.http_timeout', 10))->acceptJson()->withHeaders($headers);
            $body    = trim($step->render((string) ($step->data['body'] ?? '')));
            $payload = $body !== '' ? json_decode($body, true) : null;

            $response = $method === 'GET'
                ? $request->get($url)
                : $request->send($method, $url, is_array($payload) ? ['json' => $payload] : ($body !== '' ? ['body' => $body] : []));
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

    public function problem(array $data): ?string
    {
        return trim((string) ($data['url'] ?? '')) === '' ? $this->missing('url') : null;
    }
}
