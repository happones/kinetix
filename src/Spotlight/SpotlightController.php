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
        /** @var array<string, int> $priorities */
        $priorities = [];

        foreach (app(SpotlightRegistry::class)->sources() as $source) {
            if (! $source->authorizedFor($user)) {
                continue;
            }

            $priority = $source instanceof HasSpotlightPriority ? $source->getPriority() : 0;

            foreach ($source->search($query) as $item) {
                $grouped[$item->group][] = $item;
                // Two sources may feed one group; the most insistent wins.
                $priorities[$item->group] = max($priorities[$item->group] ?? PHP_INT_MIN, $priority);
            }
        }

        $groups = [];

        foreach ($grouped as $label => $items) {
            $groups[] = ['label' => $label, 'items' => $items];
        }

        // Stable as of PHP 8.0, so equal priorities keep registration order
        // instead of shuffling between deploys.
        usort($groups, fn (array $a, array $b): int => $priorities[$b['label']] <=> $priorities[$a['label']]);

        return response()->json(['groups' => $groups]);
    }

    /**
     * The shortest query worth hitting the database for.
     *
     * Enforced by `SpotlightResource` (the expensive sources — `%a%` matches
     * nearly every row of every table, and one character is the first thing
     * every user types) rather than on the endpoint as a whole, so the cheap
     * in-memory sources keep answering short and empty queries as they always
     * have. Shared with the client through `kinetix_config.spotlight.min_chars`
     * so the palette's gate agrees with this one, and public so a host's own
     * expensive source can honor the same floor.
     */
    public static function minChars(): int
    {
        return max(1, (int) config('kinetix.spotlight.min_chars', 2));
    }
}
