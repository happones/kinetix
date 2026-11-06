<?php

declare(strict_types=1);

namespace Happones\Kinetix\Notifications;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The bell's notifications: the `kinetix_notifications` shared prop and the
 * `GET {prefix}/notifications` feed the bell polls both read from here, so the
 * two never disagree.
 *
 * Database mode: the user's latest notifications, limited to the active team's
 * plus the global ones when notifications are team-scoped
 * ({@see NotificationScope}). Otherwise the session-flashed ones.
 *
 * @internal
 */
final class NotificationFeed
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function for(?Authenticatable $user): array
    {
        $isDatabase = (bool) config('kinetix.notifications.database', false);
        $limit      = (int) config('kinetix.notifications.limit', 15);

        if ($isDatabase && $user !== null && method_exists($user, 'notifications')) {
            // `reorder()`: the relation already orders by `created_at`, and a
            // second identical ORDER BY only costs the planner.
            $query = $user->notifications()->reorder()->latest();
            NotificationScope::apply($query);

            return $query
                ->take($limit)
                ->get()
                ->map(fn ($notification): array => array_merge((array) $notification->data, [
                    'id'         => $notification->id,
                    'read'       => ! is_null($notification->read_at),
                    'created_at' => $notification->created_at->toIso8601String(),
                ]))
                ->values()
                ->all();
        }

        return (array) session()->get('kinetix_notifications', []);
    }
}
