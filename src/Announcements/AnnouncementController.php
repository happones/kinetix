<?php

declare(strict_types=1);

namespace Happones\Kinetix\Announcements;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service announcements feed: each user reads the published feed and marks
 * it seen (clearing their own unread count), or dismisses a single entry from
 * the banner.
 */
class AnnouncementController
{
    /** Levels are host-defined slugs; anything else is not a level. */
    protected const LEVEL_PATTERN = '/^[A-Za-z0-9_-]{1,32}$/';

    public function __construct(protected AnnouncementManager $manager) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json([
            'announcements' => $this->manager->feed($user),
            'unread'        => $this->manager->unreadCount($user),
        ]);
    }

    /**
     * The banner feed: published entries this user hasn't dismissed.
     */
    public function banner(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit'  => ['sometimes', 'integer', 'min:1', 'max:10'],
            'levels' => ['sometimes', 'string', 'max:200'],
        ]);

        return response()->json([
            'announcements' => $this->manager->banner(
                $this->user($request),
                // Clamped, since the fallback comes from config rather than the
                // validated request.
                max(1, min(10, (int) ($validated['limit'] ?? config('kinetix.announcements.banner_limit', 3)))),
                $this->levels($validated['levels'] ?? null),
            ),
        ]);
    }

    /**
     * Hide one announcement from this user's banner. Resolved through the
     * team-scoped query, so an id from another tenant is a 404.
     */
    public function dismiss(Request $request): JsonResponse
    {
        $user = $this->user($request);

        // Read by name, not as a method argument: scalar route parameters are
        // injected positionally, so with teams on the `{current_team}` segment
        // would land here instead of the announcement.
        $model = Announcement::query()
            ->forCurrentTeamOrGlobal()
            ->published()
            ->whereKey($request->route('announcement'))
            ->first();

        abort_if($model === null, 404);

        $this->manager->dismiss($user, $model);

        return response()->json(['status' => 'success']);
    }

    public function seen(Request $request): JsonResponse
    {
        $this->manager->markSeen($this->user($request));

        return response()->json(['status' => 'success']);
    }

    /**
     * @return array<int, string>
     */
    protected function levels(?string $levels): array
    {
        if ($levels === null || $levels === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $levels)),
            fn (string $level): bool => preg_match(static::LEVEL_PATTERN, $level) === 1,
        ));
    }

    protected function user(Request $request): Model
    {
        $user = $request->user();

        abort_if(! $user instanceof Model, 401);

        return $user;
    }
}
