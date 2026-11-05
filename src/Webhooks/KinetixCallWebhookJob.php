<?php

declare(strict_types=1);

namespace Happones\Kinetix\Webhooks;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Happones\Kinetix\Support\BlockedOutboundUrl;
use Happones\Kinetix\Support\OutboundHttp;
use Spatie\WebhookServer\CallWebhookJob;

/**
 * The spatie/laravel-webhook-server delivery job Kinetix dispatches: the same
 * call, but the URL is validated again when the job runs (not only when it was
 * queued), the connection is pinned to the validated address, and redirects
 * are never followed.
 *
 * Extension point: to customise spatie deliveries, point
 * `webhook-server.webhook_job` at a subclass of THIS class (a plain
 * `CallWebhookJob` subclass is replaced by this one). Override what you need,
 * but keep {@see getClient()}'s guard: call `parent::getClient()` or rebuild a
 * client with the same `allow_redirects => false` and CURLOPT_RESOLVE pin.
 */
class KinetixCallWebhookJob extends CallWebhookJob
{
    protected function getClient(): ClientInterface
    {
        $target = WebhookUrlGuard::target((string) $this->webhookUrl);

        if ($target === null) {
            throw BlockedOutboundUrl::for((string) $this->webhookUrl);
        }

        $pin = OutboundHttp::pin($target);

        return new Client(array_filter([
            'allow_redirects' => false,
            'curl'            => $pin === null ? null : [CURLOPT_RESOLVE => [$pin]],
        ], static fn (mixed $option): bool => $option !== null));
    }
}
