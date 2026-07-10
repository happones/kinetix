<?php

declare(strict_types=1);

namespace Happones\Kinetix\Webhooks;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Delivers one event to one endpoint: re-checks SSRF, signs the body with the
 * endpoint secret (HMAC-SHA256, `X-Kinetix-Signature`), POSTs it, and logs the
 * attempt. Non-2xx / transport errors throw so the queue retries with backoff.
 */
class DispatchWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public int|string $endpointId,
        public string $event,
        public array $payload,
    ) {}

    public function tries(): int
    {
        return (int) config('kinetix.webhooks.tries', 3);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(): void
    {
        $endpoint = WebhookEndpoint::find($this->endpointId);

        if ($endpoint === null || ! $endpoint->active) {
            return;
        }

        $attempt = $this->attempts() > 0 ? $this->attempts() : 1;

        if (! WebhookUrlGuard::isAllowed($endpoint->url)) {
            $this->log($endpoint, null, false, $attempt, 'Blocked: URL failed SSRF validation.');

            return; // do not retry a blocked URL
        }

        $body      = (string) json_encode(['event' => $this->event, 'data' => $this->payload]);
        $signature = hash_hmac('sha256', $body, $endpoint->secret);

        try {
            $response = Http::timeout((int) config('kinetix.webhooks.timeout', 10))
                ->withHeaders([
                    'X-Kinetix-Event'     => $this->event,
                    'X-Kinetix-Signature' => $signature,
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);
        } catch (\Throwable $e) {
            $this->log($endpoint, null, false, $attempt, Str::limit($e->getMessage(), 1000));

            throw $e; // retry
        }

        $this->log($endpoint, $response->status(), $response->successful(), $attempt, Str::limit($response->body(), (int) config('kinetix.webhooks.response_limit', 1000)));

        if (! $response->successful()) {
            throw new RuntimeException("Webhook delivery to endpoint {$endpoint->getKey()} returned {$response->status()}.");
        }
    }

    protected function log(WebhookEndpoint $endpoint, ?int $status, bool $success, int $attempt, ?string $response): void
    {
        WebhookLog::create([
            'webhook_endpoint_id' => $endpoint->getKey(),
            'event'               => $this->event,
            'payload'             => config('kinetix.webhooks.log_payloads', true) ? $this->payload : null,
            'status_code'         => $status,
            'success'             => $success,
            'attempt'             => $attempt,
            'response'            => $response,
        ]);
    }
}
