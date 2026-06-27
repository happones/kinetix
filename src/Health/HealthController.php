<?php

declare(strict_types=1);

namespace Happones\Kinetix\Health;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Returns the latest application-health snapshot as JSON for the
 * <KinetixHealthStatus> widget. Gated by the `viewKinetixHealth` ability
 * (defaults to allow in `local`).
 */
class HealthController
{
    public function __construct(protected HealthMetrics $metrics) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewKinetixHealth');

        return response()->json($this->metrics->snapshot());
    }
}
