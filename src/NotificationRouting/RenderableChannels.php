<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting;

use Illuminate\Support\Str;

/**
 * The channels a notification class can actually render a message for: a
 * class with no `toMail()` cannot go out by mail, whatever a route says.
 *
 * @internal
 */
final class RenderableChannels
{
    /**
     * @param  list<string> $channels
     * @return list<string>
     */
    public static function of(object $notification, array $channels): array
    {
        return array_values(array_filter(
            $channels,
            static fn (string $channel): bool => self::renders($notification, $channel),
        ));
    }

    public static function renders(object $notification, string $channel): bool
    {
        return match ($channel) {
            'database'  => method_exists($notification, 'toDatabase')  || method_exists($notification, 'toArray'),
            'broadcast' => method_exists($notification, 'toBroadcast') || method_exists($notification, 'toArray'),
            default     => method_exists($notification, 'to'.Str::studly($channel)),
        };
    }
}
