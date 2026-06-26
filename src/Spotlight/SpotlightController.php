<?php

declare(strict_types=1);

namespace Happones\Kinetix\Spotlight;

use Happones\Kinetix\Data\SpotlightItemData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Runs the query across every authorized source and returns grouped, normalized
 * results. Authorization is enforced by the sources themselves (source-level
 * ability + per-record policy), so results never leak records a user can't see.
 */
class SpotlightController
{
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        $user  = $request->user();

        /** @var array<string, array<int, SpotlightItemData>> $grouped */
        $grouped = [];

        foreach (app(SpotlightRegistry::class)->sources() as $source) {
            if (! $source->authorizedFor($user)) {
                continue;
            }

            foreach ($source->search($query) as $item) {
                $grouped[$item->group][] = $item;
            }
        }

        $groups = [];

        foreach ($grouped as $label => $items) {
            $groups[] = ['label' => $label, 'items' => $items];
        }

        return response()->json(['groups' => $groups]);
    }
}
