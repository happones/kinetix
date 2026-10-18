<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels;

use Happones\Kinetix\Channels\Events\ChannelMessageReceived;
use Happones\Kinetix\Channels\Events\ChannelQrUpdated;
use Happones\Kinetix\Channels\Events\ChannelStatusChanged;
use Happones\Kinetix\Channels\Events\ChannelUpdated;
use Happones\Kinetix\Support\ConfigCallback;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * The registry of channel drivers and what every line goes through whatever
 * its provider: status transitions (with their events), webhook results,
 * status checks against the provider, and the line's webhook URL.
 */
class ChannelManager
{
    /** @var array<string, class-string<ChannelDriver>|ChannelDriver> */
    protected array $extensions = [];

    /** @var array<string, ChannelDriver> */
    protected array $resolved = [];

    /**
     * Register a provider (or replace a built-in one).
     *
     * @param class-string<ChannelDriver>|ChannelDriver $driver
     */
    public function extend(string $provider, string|ChannelDriver $driver): void
    {
        $this->extensions[$provider] = $driver;
        unset($this->resolved[$provider]);
    }

    /**
     * The providers a team can add lines with: the enabled ones in
     * `kinetix.channels.providers`, and any registered with extend().
     *
     * @return array<string, class-string<ChannelDriver>|ChannelDriver>
     */
    public function available(): array
    {
        $configured = [];

        foreach ((array) config('kinetix.channels.providers', []) as $provider => $settings) {
            if (is_array($settings) && ($settings['enabled'] ?? false) && is_string($settings['driver'] ?? null)) {
                /** @var class-string<ChannelDriver> $class */
                $class                 = $settings['driver'];
                $configured[$provider] = $class;
            }
        }

        return [...$configured, ...$this->extensions];
    }

    public function has(string $provider): bool
    {
        return array_key_exists($provider, $this->available());
    }

    public function driver(string $provider): ChannelDriver
    {
        if (isset($this->resolved[$provider])) {
            return $this->resolved[$provider];
        }

        $driver = $this->available()[$provider] ?? null;

        if ($driver === null) {
            throw new InvalidArgumentException("No channel driver is registered for [{$provider}].");
        }

        return $this->resolved[$provider] = $driver instanceof ChannelDriver ? $driver : app($driver);
    }

    public function for(Channel $channel): ChannelDriver
    {
        return $this->driver($channel->provider);
    }

    /**
     * The providers as the UI lists them.
     *
     * @return list<array{key: string, label: string, methods: list<string>}>
     */
    public function providers(): array
    {
        $providers = [];

        foreach (array_keys($this->available()) as $provider) {
            $label       = __("kinetix::kinetix.channel_provider_{$provider}");
            $providers[] = [
                'key'     => $provider,
                'label'   => $label === "kinetix::kinetix.channel_provider_{$provider}" ? ucfirst($provider) : $label,
                'methods' => $this->driver($provider)->linkMethods(),
            ];
        }

        return $providers;
    }

    /**
     * How many lines a team may have: `kinetix.channels.limit` — a number, a
     * callable array receiving the team key (a plan's allowance), or null
     * for no limit.
     */
    public function limit(int|string|null $teamKey): ?int
    {
        $setting  = config('kinetix.channels.limit');
        $callback = ConfigCallback::resolve($setting);
        $limit    = $callback !== null ? $callback($teamKey) : $setting;

        return is_numeric($limit) ? (int) $limit : null;
    }

    /** Where the provider calls the line: `webhook_url` (a tunnel in development) or the app URL. */
    public function webhookUrl(Channel $channel): string
    {
        $path = route('kinetix.channels.webhook', ['channel' => $channel->public_id], false);
        $base = config('kinetix.channels.webhook_url') ?: config('app.url');

        return rtrim((string) $base, '/').$path;
    }

    /**
     * Move a line to a status, recording when it connected and why it
     * failed, and announce the change.
     */
    public function transition(Channel $channel, ChannelStatus $status, ?string $handle = null, ?string $error = null): void
    {
        $from = $channel->status;

        // Connected with no number yet (linked by polling, not by a webhook
        // carrying it): ask the provider.
        if ($status === ChannelStatus::Connected && ($handle ?? '') === '' && $channel->handle === null) {
            $handle = $this->reportedHandle($channel);
        }

        $channel->status     = $status;
        $channel->last_error = $status === ChannelStatus::Failed ? $error : null;

        if ($handle !== null && $handle !== '') {
            $channel->handle = $handle;
        }

        if ($status === ChannelStatus::Connected && $from !== ChannelStatus::Connected) {
            $channel->connected_at = now();
        }

        $channel->save();

        if ($from !== $status) {
            ChannelStatusChanged::dispatch($channel, $from, $status);
            $this->broadcast($channel);
        }
    }

    /**
     * Ask the provider where the line stands, at most once per
     * `kinetix.channels.check_interval` seconds — what makes linking work
     * even when the provider's webhooks can't reach the app.
     */
    public function sync(Channel $channel, bool $force = false): Channel
    {
        $interval = (int) config('kinetix.channels.check_interval', 5);

        if (! $force && $channel->checked_at !== null && $channel->checked_at->diffInSeconds(now()) < $interval) {
            return $channel;
        }

        $channel->checked_at = now();

        try {
            $this->transition($channel, $this->for($channel)->status($channel));
        } catch (ChannelException $exception) {
            $this->transition($channel, ChannelStatus::Failed, error: $exception->getMessage());
        } catch (Throwable $exception) {
            // The provider is unreachable: keep the last known status.
            Log::warning('Kinetix channels: status check failed.', ['channel' => $channel->getKey(), 'error' => $exception->getMessage()]);
            $channel->save();
        }

        return $channel;
    }

    protected function reportedHandle(Channel $channel): ?string
    {
        $driver = $this->for($channel);

        return $driver instanceof ReportsHandle
            ? rescue(fn (): ?string => $driver->handle($channel), null, report: false)
            : null;
    }

    /** Apply what a verified webhook call carried. */
    public function apply(Channel $channel, WebhookResult $result): void
    {
        $channel->last_event_at = now();

        if ($result->status !== null) {
            $this->transition($channel, $result->status, $result->handle);
        } else {
            $channel->save();
        }

        if ($result->qr !== null) {
            ChannelQrUpdated::dispatch($channel, $result->qr);
            $this->broadcast($channel, $result->qr);
        }

        foreach ($result->messages as $message) {
            ChannelMessageReceived::dispatch($channel, $message);
        }
    }

    /** Send a text from a line. */
    public function sendText(Channel $channel, string $to, string $text): void
    {
        $this->for($channel)->sendText($channel, $to, $text);
    }

    /**
     * The private channel lines broadcast on, per team; null when
     * broadcasting is off.
     */
    public function broadcastChannel(int|string|null $teamKey): ?string
    {
        if (! config('kinetix.channels.broadcast', false)) {
            return null;
        }

        return $teamKey === null ? 'kinetix.channels' : "kinetix.channels.{$teamKey}";
    }

    protected function broadcast(Channel $channel, ?string $qr = null): void
    {
        $name = $this->broadcastChannel($channel->team_id);

        if ($name === null) {
            return;
        }

        try {
            event(new ChannelUpdated($name, [
                'id'     => $channel->getKey(),
                'status' => $channel->status->value,
                'handle' => $channel->handle,
                'qr'     => $qr,
            ]));
        } catch (Throwable $exception) {
            // A broadcaster that's down must not break a webhook or a link.
            Log::warning('Kinetix channels: broadcast failed.', ['error' => $exception->getMessage()]);
        }
    }
}
