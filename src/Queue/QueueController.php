<?php

declare(strict_types=1);

namespace Happones\Kinetix\Queue;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Live queue-health snapshot + failed-job actions for the <KinetixQueueStats>
 * widget. Gated by the `viewKinetixQueue` ability (defaults to allow in `local`).
 */
class QueueController
{
    public function __construct(protected QueueMetrics $metrics) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewKinetixQueue');

        return response()->json($this->metrics->snapshot());
    }

    public function retry(Request $request): JsonResponse
    {
        Gate::authorize('viewKinetixQueue');

        $id = (string) $request->validate(['id' => ['required', 'string']])['id'];

        return response()->json(['status' => $this->metrics->retry($id) ? 'success' : 'unavailable']);
    }

    public function forget(Request $request): JsonResponse
    {
        Gate::authorize('viewKinetixQueue');

        $id = (string) $request->validate(['id' => ['required', 'string']])['id'];
        $this->metrics->forget($id);

        return response()->json(['status' => 'success']);
    }
}
