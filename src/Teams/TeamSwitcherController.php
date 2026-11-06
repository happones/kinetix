<?php

declare(strict_types=1);

namespace Happones\Kinetix\Teams;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The team switcher's search, for a user in more teams than
 * `team_switcher.max_teams` shares: `GET …/team-switcher/teams?q=`, over the
 * user's OWN teams only.
 */
class TeamSwitcherController
{
    public function search(Request $request, TeamSwitcherManager $teams): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof Model) {
            abort(401);
        }

        $query = trim((string) $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:100'],
        ])['q']);

        if ($query === '') {
            return $this->noStore(['teams' => [], 'truncated' => false]);
        }

        return $this->noStore($teams->search($user, $query));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function noStore(array $body): JsonResponse
    {
        return response()->json($body)->header('Cache-Control', 'no-store, private');
    }
}
