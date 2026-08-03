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
     * Mark every unread notification as read.
     */
    public function readAll(Request $request): JsonResponse
    {
        $request->user()?->unreadNotifications->markAsRead();

        return response()->json(['status' => 'success']);
    }

    /**
     * Delete every notification.
     */
    public function clearAll(Request $request): JsonResponse
    {
        $request->user()?->notifications()->delete();

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
