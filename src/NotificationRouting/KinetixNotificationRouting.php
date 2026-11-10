<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting;

use Happones\Kinetix\NotificationPreferences\NotificationTypeRegistry;

/**
 * Send a platform notification to whoever the team routed it to.
 *
 * ```php
 * KinetixNotificationPreferences::types(['inventory.low-stock' => 'Low stock']);
 *
 * KinetixNotificationRouting::type('inventory.low-stock')
 *     ->requires('products.viewAny')
 *     ->mandatory(['mail']);
 *
 * KinetixNotificationRouting::for('inventory.low-stock', $team->getKey())
 *     ->fallback($team->owners)
 *     ->notify(new LowStockAlert($product));
 * ```
 */
class KinetixNotificationRouting
{
    /**
     * The routing metadata of a type (the type itself is registered with
     * `KinetixNotificationPreferences::types()`).
     */
    public static function type(string $type): RoutableType
    {
        return new RoutableType(app(NotificationTypeRegistry::class), $type);
    }

    /**
     * Route a notification of $type for a team: $teamKey is the team's
     * PRIMARY key (null when the app is not team-scoped).
     */
    public static function for(string $type, int|string|null $teamKey = null): PendingRouting
    {
        return new PendingRouting($type, $teamKey);
    }
}
