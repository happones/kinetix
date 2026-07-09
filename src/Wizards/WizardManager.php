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

    protected function teamId(Model $user): int|string|null
    {
        if (! KinetixTeams::enabledFor('wizards')) {
            return null;
        }

        $team = $user->getAttribute('currentTeam');

        return $team instanceof Model ? $team->getKey() : null;
    }
}
