<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels;

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
}
