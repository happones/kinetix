<?php

declare(strict_types=1);

namespace Happones\Kinetix\Activity;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Returns the paginated, team-scoped activity feed as JSON. Gated by the
 * `activity.view` ability. Filter by `subject_type` + `subject_id` (the
 * per-feature view) and/or `event`. `per_page` is capped at
 * `activity.max_per_page`.
 */
class ActivityController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('activity.view');

        $filters = $request->only(['subject_type', 'subject_id', 'event', 'page', 'per_page']);

        if (array_key_exists('per_page', $filters)) {
            $filters['per_page'] = $this->perPage($filters['per_page']);
        }

        return response()->json(app(ActivityLogger::class)->query($filters));
    }

    /**
     * The page size a request may ask for: 1..`activity.max_per_page`
     * (default 100); anything below 1 or unreadable is the default size.
     * Only the HTTP feed is clamped: PHP callers of the logger choose freely.
     */
    private function perPage(mixed $requested): int
    {
        $default = max(1, (int) config('kinetix.activity.per_page', 15));
        $max     = max(1, (int) config('kinetix.activity.max_per_page', 100));
        $size    = is_numeric($requested) ? (int) $requested : 0;

        return $size < 1 ? min($default, $max) : min($size, $max);
    }
}
