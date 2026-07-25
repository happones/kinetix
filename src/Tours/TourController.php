<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tours;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Seen-state endpoints for the `database` tours driver: marking is idempotent,
 * reset re-arms the tour (a "replay tour" affordance). Only registered when
 * `kinetix.tours.driver` is `database`; the `local` driver never calls home.
 */
class TourController
{
    public function seen(Request $request): JsonResponse
    {
        $tourId = $this->tourId($request);

        TourState::query()->updateOrCreate(
            ['user_id' => $request->user()->getAuthIdentifier(), 'tour_id' => $tourId],
            ['seen_at' => now()],
        );

        return response()->json(['status' => 'success']);
    }

    public function reset(Request $request): JsonResponse
    {
        TourState::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->where('tour_id', $this->tourId($request))
            ->delete();

        return response()->json(['status' => 'success']);
    }

    /**
     * The tour id from the route, validated against the registry so arbitrary
     * strings never reach storage.
     */
    protected function tourId(Request $request): string
    {
        // Resolve by name (not positionally): with teams enabled the route
        // gains a leading `{current_team}` param.
        $tourId = (string) $request->route('tour');

        abort_unless(
            array_key_exists($tourId, app(TourRegistry::class)->tours()),
            404,
        );

        return $tourId;
    }
}
