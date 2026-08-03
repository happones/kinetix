<?php

declare(strict_types=1);

namespace Happones\Kinetix\Wizards;

use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Database\Eloquent\Model;

/**
 * Persists and queries per-user (optionally per-team) completion of named
 * wizards. Backs the `kinetix.wizard:<slug>` gating middleware.
 */
class WizardManager
{
    public function hasCompleted(Model $user, string $slug): bool
    {
        return WizardCompletion::query()
            ->where('user_id', $user->getKey())
            ->where('team_id', $this->teamId($user))
            ->where('slug', $slug)
            ->whereNotNull('completed_at')
            ->exists();
    }

    public function complete(Model $user, string $slug): void
    {
        $completion = WizardCompletion::firstOrNew([
            'user_id' => $user->getKey(),
            'team_id' => $this->teamId($user),
            'slug'    => $slug,
        ]);

        if ($completion->completed_at === null) {
            $completion->completed_at = now();
            $completion->save();
        }
    }

    public function reset(Model $user, string $slug): void
    {
        WizardCompletion::query()
            ->where('user_id', $user->getKey())
            ->where('team_id', $this->teamId($user))
            ->where('slug', $slug)
            ->delete();
    }

    /**
     * The active team: the `{current_team}` segment when the call happens in a
     * request (so a page served for team B never writes team A's row), falling
     * back to the given user's `currentTeam` outside one — queued jobs and
     * console runs, where the user is explicit and there is no route.
     */
    protected function teamId(Model $user): int|string|null
    {
        if (! KinetixTeams::enabledFor('wizards')) {
            return null;
        }

        $team = KinetixTeams::currentTeamKey();

        if ($team !== null) {
            return $team;
        }

        $fallback = $user->getAttribute('currentTeam');

        return $fallback instanceof Model ? $fallback->getKey() : null;
    }
}
