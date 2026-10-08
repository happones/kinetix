<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables;

use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Support\DescriptorRejection;
use Happones\Kinetix\Support\SignedDescriptor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Computes a table's DEFERRED aggregates — the KPI `stats()` and column
 * `summarize()` totals that `Table::deferStats()` kept off the first paint.
 *
 * The frontend calls this once mounted, passing the table's signed descriptor
 * and the current search/filter query params; the endpoint rebuilds the table
 * from its resource (the only reconstructible source — the client never names a
 * class), re-runs the aggregates over the SAME filtered window the user sees,
 * and returns just `{ stats, summaries, hasSummaries }`. The descriptor is
 * bound to the user/team/expiry like every Kinetix descriptor, and the table's
 * own `getEloquentQuery()` scope + column gating still apply. A table only
 * defers when this rebuild matches what the page rendered (same base query,
 * columns, filters, summarizers and stats — see Table::aggregatesAreDeferred()),
 * so the totals always describe the rows the user was shown.
 */
class AggregatesController
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = SignedDescriptor::open((string) $request->input('descriptor'));

        if ($payload === null) {
            return response()->json(['message' => __('kinetix.table_invalid_signature')], 400);
        }

        $rejection = SignedDescriptor::rejection($payload, $request);

        if ($rejection !== null) {
            return response()->json([
                'message' => $rejection === DescriptorRejection::Expired
                    ? __('kinetix.table_descriptor_expired')
                    : __('kinetix.table_write_forbidden'),
            ], 403);
        }

        $resource = $payload['resource'] ?? null;

        if (! is_string($resource) || ! class_exists($resource) || ! is_subclass_of($resource, Resource::class)) {
            return response()->json(['message' => __('kinetix.table_invalid_model')], 400);
        }

        // Rebuild the table exactly as the resource page does, so its columns,
        // summarizers, stats, filters and scope match what was serialized. The
        // request carries the active search/filter params (namespaced by the
        // table's queryPrefix), which getResolvedQuery() applies — so the
        // aggregates describe the window the user is actually looking at.
        $prefix = is_string($payload['queryPrefix'] ?? null) ? $payload['queryPrefix'] : '';

        $table = Table::rebuildFromResource($resource);

        if ($prefix !== '') {
            $table->queryPrefix($prefix);
        }

        return response()->json($table->aggregates());
    }
}
