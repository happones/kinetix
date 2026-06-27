<?php

declare(strict_types=1);

namespace Happones\Kinetix\Announcements;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service announcements feed: each user reads the published feed and marks
 * it seen (clearing their own unread count).
 */
class AnnouncementController
{
    public function __construct(protected AnnouncementManager $manager) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json([
            'announcements' => $this->manager->feed($user),
            'unread'        => $this->manager->unreadCount($user),
        ]);
    }

    public function seen(Request $request): JsonResponse
    {
        $this->manager->markSeen($this->user($request));

        return response()->json(['status' => 'success']);
    }

    protected function user(Request $request): Model
    {
        $user = $request->user();

        abort_if(! $user instanceof Model, 401);

        return $user;
    }
}
