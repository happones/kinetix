<?php

declare(strict_types=1);

namespace Happones\Kinetix\Webhooks;

/**
 * Fans a fired event out to the active, subscribed endpoints in the current team
 * scope by queueing a signed delivery per endpoint. Only registered events fire.
 */
class WebhookDispatcher
{
    public function __construct(protected WebhookEventRegistry $events) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function fire(string $event, array $payload = []): void
    {
        if (! $this->events->has($event)) {
            return;
        }

        WebhookEndpoint::query()
            ->where('active', true)
            ->where('team_id', $this->teamId())
            ->get()
            ->filter(static fn (WebhookEndpoint $endpoint): bool => in_array($event, $endpoint->events, true))
            ->each(static fn (WebhookEndpoint $endpoint) => DispatchWebhookJob::dispatch($endpoint->getKey(), $event, $payload));
    }

    protected function teamId(): int|string|null
    {
        if (! config('kinetix.webhooks.teams', false)) {
            return null;
        }

        return auth()->user()?->currentTeam?->getKey();
    }
}
