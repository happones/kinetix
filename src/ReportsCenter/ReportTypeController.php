<?php

declare(strict_types=1);

namespace Happones\Kinetix\ReportsCenter;

use Happones\Kinetix\Data\ReportTypeData;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Lists every registered `Report` type for the launcher UI.
 */
class ReportTypeController
{
    public function __construct(protected ReportRegistry $registry) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewKinetixReportsCenter');

        $types = array_map(
            static fn (string $class): ReportTypeData => ReportTypeData::fromClass($class),
            $this->registry->all(),
        );

        return response()->json($types);
    }
}
