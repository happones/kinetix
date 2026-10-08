<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationPreferences;

use Illuminate\Database\Eloquent\Model;

/**
 * Static entry point for notification preferences. Declare the opt-in/out types
 * in a provider, then gate sends against them:
 *
 *     KinetixNotificationPreferences::types([
 *         'orders'    => 'Order updates',
 *         'marketing' => 'Marketing & tips',
 *     ]);
 *
 *     // In a Notification's via():
 *     return KinetixNotificationPreferences::channelsFor($notifiable, 'orders', ['mail', 'database']);
 */
class KinetixNotificationPreferences
{
    /**
     * The types Kinetix's own notifications carry. They're delivered on every
     * channel until you register one in `kinetix.notification_preferences.types`;
     * from then on it shows in the matrix and each user's choice applies.
     */
    public const EXPORTS = 'kinetix.exports';

    public const IMPORTS = 'kinetix.imports';

    public const REPORTS = 'kinetix.reports';

    public const DATA_EXPORTS = 'kinetix.data-exports';

    public static function registry(): NotificationTypeRegistry
    {
        return app(NotificationTypeRegistry::class);
    }

    /**
     * @param array<int|string, string> $types
     */
    public static function types(array $types): void
    {
        static::registry()->register($types);
    }

    public static function manager(): NotificationPreferenceManager
    {
        return app(NotificationPreferenceManager::class);
    }

    public static function allows(Model $user, string $type, string $channel): bool
    {
        return static::manager()->allows($user, $type, $channel);
    }

    /**
     * Filter candidate channels by the user's preferences for a type.
     *
     * @param  array<int, string> $channels
     * @return array<int, string>
     */
    public static function channelsFor(Model $user, string $type, array $channels): array
    {
        return static::manager()->channelsFor($user, $type, $channels);
    }
}
