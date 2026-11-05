<?php

declare(strict_types=1);

namespace Happones\Kinetix\ReportsCenter;

use Happones\Kinetix\Data\ReportTypeData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Lists every registered `Report` type for the launcher UI.
 */
class ReportTypeController
{
    public function __construct(protected ReportRegistry $registry) {}

    /**
     * The types the viewer may launch: a report whose own `authorize()`
     * refuses them is not listed (launching it would 403 anyway).
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewKinetixReportsCenter');

        $types = [];

        foreach ($this->registry->all() as $class) {
            if ((new $class)->authorize($request->user())) {
                $types[] = ReportTypeData::fromClass($class);
            }
        }

        return response()->json($types);
    }
}
