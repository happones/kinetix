<?php

declare(strict_types=1);

namespace Happones\Kinetix\Activity;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Returns the paginated, team-scoped activity feed as JSON. Gated by the
 * `activity.view` ability. Filter by `subject_type` + `subject_id` (the
 * per-feature view) and/or `event`.
 */
class ActivityController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('activity.view');

        $filters = $request->only(['subject_type', 'subject_id', 'event', 'page', 'per_page']);

        return response()->json(app(ActivityLogger::class)->query($filters));
    }
}
