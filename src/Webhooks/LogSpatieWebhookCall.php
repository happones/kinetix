<?php

declare(strict_types=1);

namespace Happones\Kinetix\Webhooks;

use Illuminate\Support\Str;
use Spatie\WebhookServer\Events\WebhookCallEvent;
use Spatie\WebhookServer\Events\WebhookCallSucceededEvent;

/**
 * Bridges spatie/laravel-webhook-server's per-attempt events into the Kinetix
 * delivery log, so the dashboard shows the same history regardless of driver.
 * Only logs calls Kinetix originated (identified by the `kinetix_endpoint_id`
 * meta the dispatcher attaches).
 */
class LogSpatieWebhookCall
{
    public function handle(WebhookCallEvent $event): void
    {
        $endpointId = $event->meta['kinetix_endpoint_id'] ?? null;

        if ($endpointId === null) {
            return; // not a Kinetix-dispatched webhook
        }

        $payload = is_array($event->payload) ? ($event->payload['data'] ?? $event->payload) : [];

        $body = $event->response !== null
            ? (string) $event->response->getBody()
            : ($event->errorMessage ?? '');

        WebhookLog::create([
            'webhook_endpoint_id' => $endpointId,
            'event'               => $event->meta['kinetix_event'] ?? 'unknown',
            'payload'             => config('kinetix.webhooks.log_payloads', true) ? $payload : null,
            'status_code'         => $event->response?->getStatusCode(),
            'success'             => $event instanceof WebhookCallSucceededEvent,
            'attempt'             => $event->attempt,
            'response'            => Str::limit($body, (int) config('kinetix.webhooks.response_limit', 1000)),
        ]);
    }
}
