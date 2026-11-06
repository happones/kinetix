<?php

declare(strict_types=1);

namespace Happones\Kinetix\Notifications;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service endpoints for the authenticated user's database notifications.
 *
 * Every query is rooted at `$request->user()->notifications()`, so a tampered id
 * simply matches nothing — there is no cross-user read or write to guard against
 * beyond that rooting.
 *
 * These live in a controller (rather than closures in the service provider) so a
 * host running `php artisan route:cache` on deploy can serialize them.
 */
class NotificationController
{
    /**
     * The bell's feed as JSON, for its poll and its realtime refresh: the
     * same items as the `kinetix_notifications` shared prop, without running
     * the host page's controller the way a partial reload does.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_if($user === null, 401);

        return response()
            ->json(['notifications' => NotificationFeed::for($user)])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Mark one notification as read.
     */
    public function read(Request $request): JsonResponse
    {
        // Resolve by name (not positionally): with teams enabled the prefix adds
        // a leading `{current_team}` param, so a positional argument would
        // receive the team, not the id.
        $id = (string) $request->route('id');

        $request->user()?->unreadNotifications()->where('id', $id)->first()?->markAsRead();

        return response()->json(['status' => 'success']);
    }

    /**
     * Mark every unread notification the bell shows as read, in one query.
     * With team-scoped notifications, another team's stay unread
     * ({@see NotificationScope}).
     */
    public function readAll(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user !== null && method_exists($user, 'unreadNotifications')) {
            NotificationScope::apply($user->unreadNotifications())->update(['read_at' => now()]);
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Delete every notification the bell shows. With team-scoped
     * notifications, another team's are kept ({@see NotificationScope}).
     */
    public function clearAll(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user !== null && method_exists($user, 'notifications')) {
            NotificationScope::apply($user->notifications())->delete();
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Delete one notification.
     */
    public function destroy(Request $request): JsonResponse
    {
        $id = (string) $request->route('id');

        $request->user()?->notifications()->where('id', $id)->delete();

        return response()->json(['status' => 'success']);
    }
}
