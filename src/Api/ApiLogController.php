<?php

declare(strict_types=1);

namespace Happones\Kinetix\Api;

use Happones\Kinetix\Data\ApiLogData;
use Happones\Kinetix\Query\KinetixQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only feed of logged API requests for the integration-logs viewer,
 * gated by `viewKinetixApiLogs` (local-only by default — define the gate).
 */
class ApiLogController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewKinetixApiLogs');

        $query = ApiLog::query()->forCurrentTeam()->latest('created_at')->latest('id');

        // Status band filter: success (2xx/3xx) vs failed (4xx/5xx).
        if ($request->query('result') === 'success') {
            $query->where('status', '<', 400);
        } elseif ($request->query('result') === 'failed') {
            $query->where('status', '>=', 400);
        }

        if (($search = trim((string) $request->query('search'))) !== '') {
            KinetixQuery::search($query, $search, ['path', 'token_name']);
        }

        $paginator = $query->paginate(15);

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(static fn (ApiLog $log): ApiLogData => ApiLogData::fromModel($log))
                ->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }
}
