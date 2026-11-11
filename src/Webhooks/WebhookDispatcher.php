<?php

declare(strict_types=1);

namespace Happones\Kinetix\Webhooks;

use Happones\Kinetix\Permissions\PermissionTeam;
use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Spatie\WebhookServer\WebhookCall;

/**
 * Fans a fired event out to the active, subscribed endpoints in the current team
 * scope. With teams on, `fire()` without a resolvable team (a job, the
 * scheduler, the console) delivers nothing unless `webhooks.unresolved_team`
 * is 'platform'. A routed request or a pinned team (PermissionTeam::pin) counts
 * as the team; the auth user's `currentTeam` outside a request does not. Delivery goes through the configured driver: `spatie/laravel-webhook-server`
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
        $teamId = $this->hasTeamContext() ? $this->teamId() : null;

        if ($teamId === null && KinetixTeams::enabledFor('webhooks') && $this->unresolvedTeamSkips()) {
            if ($this->events->has($event)) {
                Log::warning('Kinetix webhooks: no team resolved, nothing delivered. Use fireFor($team, ...) outside a team request.', ['event' => $event]);
            }

            return;
        }

        $this->fireFor($teamId, $event, $payload);
    }

    /**
     * Fire to one team's endpoints (`null`: the platform's, those with no
     * team, an explicit choice) whatever team the request serves: a queued
     * job, a console command or a record whose team you know. With webhooks
     * not team-scoped, every endpoint is the platform's, and the team is
     * ignored.
     *
     * A Model is taken AS the team. `fireFor($record->team_id, ...)` on a
     * nullable column passes `null` for a record with no team and reaches the
     * platform's endpoints with that record's payload: check it first.
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

    /**
     * Whether something says which team this call serves: a routed request
     * (a team route or the user's team on it) or a pinned team
     * ({@see PermissionTeam::pin()}). Without either (a queued job, the
     * console, the scheduler) the user a worker may still have authenticated
     * is no evidence of the team, so `currentTeam` is never consulted.
     */
    protected function hasTeamContext(): bool
    {
        return PermissionTeam::pinned() !== null || request()->route() !== null;
    }

    protected function unresolvedTeamSkips(): bool
    {
        return config('kinetix.webhooks.unresolved_team', 'skip') !== 'platform';
    }

    protected function teamId(): int|string|null
    {
        return KinetixTeams::keyFor('webhooks');
    }
}
