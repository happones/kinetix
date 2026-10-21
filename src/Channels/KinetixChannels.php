<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels;

use Happones\Kinetix\Channels\Evolution\WhatsAppBot;

/**
 * The static entry point to Kinetix lines: register a provider, send from a
 * line, read where it stands.
 *
 * ```php
 * KinetixChannels::extend('sms', SmsDriver::class);
 * KinetixChannels::sendText($channel, '+5215512345678', 'Your order shipped.');
 * ```
 */
class KinetixChannels
{
    /**
     * @param class-string<ChannelDriver>|ChannelDriver $driver
     */
    public static function extend(string $provider, string|ChannelDriver $driver): void
    {
        app(ChannelManager::class)->extend($provider, $driver);
    }

    public static function driver(string $provider): ChannelDriver
    {
        return app(ChannelManager::class)->driver($provider);
    }

    public static function sendText(Channel $channel, string $to, string $text): void
    {
        app(ChannelManager::class)->sendText($channel, $to, $text);
    }

    /** Check a line against its provider now, ignoring the check interval. */
    public static function sync(Channel $channel): Channel
    {
        return app(ChannelManager::class)->sync($channel, force: true);
    }

    public static function webhookUrl(Channel $channel): string
    {
        return app(ChannelManager::class)->webhookUrl($channel);
    }

    /**
     * Put a provider-run bot on a line (an Evolution integration on WhatsApp),
     * replacing the one it had; Kinetix Flows then leave the line to it.
     */
    public static function attachBot(Channel $channel, WhatsAppBot $bot): Channel
    {
        static::botHost($channel)->attachBot($channel, $bot);
        $channel->save();

        return $channel;
    }

    /** Take a line's provider-run bot off. */
    public static function detachBot(Channel $channel): Channel
    {
        static::botHost($channel)->detachBot($channel);
        $channel->save();

        return $channel;
    }

    protected static function botHost(Channel $channel): HostsBots
    {
        $driver = app(ChannelManager::class)->driver($channel->provider);

        if (! $driver instanceof HostsBots) {
            throw new ChannelException("The [{$channel->provider}] provider doesn't run bots of its own.");
        }

        return $driver;
    }
}
