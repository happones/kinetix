<?php

declare(strict_types=1);

namespace Happones\Kinetix\Webhooks;

use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Database\Eloquent\Model;
use Spatie\WebhookServer\WebhookCall;

/**
 * Fans a fired event out to the active, subscribed endpoints in the current team
 * scope. Delivery goes through the configured driver: `spatie/laravel-webhook-server`
 * when installed (its tuned retries/backoff), otherwise the native queued job.
 * Only registered events fire, and every endpoint URL is SSRF-checked at dispatch.
 */
class WebhookDispatcher
{
    public function __construct(protected WebhookEventRegistry $events) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function fire(string $event, array $payload = []): void
    {
        $this->fireFor($this->teamId(), $event, $payload);
    }

    /**
     * Fire to one team's endpoints (`null`: the platform's, those with no
     * team) whatever team the request serves: a queued job, a console command
     * or a record whose team you know. With webhooks not team-scoped, every
     * endpoint is the platform's, and the team is ignored.
     *
     * @param array<string, mixed> $payload
     */
    public function fireFor(Model|int|string|null $team, string $event, array $payload = []): void
    {
        if (! $this->events->has($event)) {
            return;
        }

        $teamId = KinetixTeams::enabledFor('webhooks') && $team !== null
            ? ($team instanceof Model ? $team->getKey() : $team)
            : null;

        WebhookEndpoint::query()
            ->where('active', true)
            ->where('team_id', $teamId)
            ->get()
            ->filter(static fn (WebhookEndpoint $endpoint): bool => in_array($event, $endpoint->events, true))
            ->each(fn (WebhookEndpoint $endpoint) => $this->deliver($endpoint, $event, $payload));
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function deliver(WebhookEndpoint $endpoint, string $event, array $payload): void
    {
        if (! $this->usesWebhookServer()) {
            DispatchWebhookJob::dispatch($endpoint->getKey(), $event, $payload);

            return;
        }

        // The native job re-checks SSRF before sending; spatie delivers directly,
        // so guard here. Endpoints that fail are simply skipped.
        if (! WebhookUrlGuard::isAllowed($endpoint->url)) {
            return;
        }

        $this->webhookCall()
            ->url($endpoint->url)
            ->payload(['event' => $event, 'data' => $payload])
            ->useSecret($endpoint->secret)
            ->meta([
                'kinetix_endpoint_id' => $endpoint->getKey(),
                'kinetix_event'       => $event,
            ])
            ->dispatch();
    }

    /**
     * A spatie call carrying the Kinetix job, which re-validates the URL when
     * it runs, pins the connection to the validated address and never follows
     * a redirect ({@see KinetixCallWebhookJob}). A host job class extending it
     * is kept.
     *
     * The job is chosen through spatie's `webhook_job` config for this one
     * call: `useJob()` after `create()` would drop the configured defaults
     * (verb, timeout, tries…) applied to the original job.
     */
    protected function webhookCall(): WebhookCall
    {
        $configured = config('webhook-server.webhook_job');
        $job        = is_string($configured) && is_a($configured, KinetixCallWebhookJob::class, true)
            ? $configured
            : KinetixCallWebhookJob::class;

        config()->set('webhook-server.webhook_job', $job);

        try {
            return WebhookCall::create();
        } finally {
            config()->set('webhook-server.webhook_job', $configured);
        }
    }

    public function usesWebhookServer(): bool
    {
        $driver = (string) config('kinetix.webhooks.driver', 'auto');

        return match ($driver) {
            'native' => false,
            'spatie' => true,
            default  => class_exists(WebhookCall::class),
        };
    }

    protected function teamId(): int|string|null
    {
        return KinetixTeams::keyFor('webhooks');
    }
}
