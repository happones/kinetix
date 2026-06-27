<?php

declare(strict_types=1);

namespace Happones\Kinetix\Queue;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Returns a live queue-health snapshot as JSON for the <KinetixQueueStats>
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
}
