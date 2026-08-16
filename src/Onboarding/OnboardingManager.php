<?php

declare(strict_types=1);

namespace Happones\Kinetix\Onboarding;

use Happones\Kinetix\Data\OnboardingData;
use Happones\Kinetix\Data\OnboardingStepData;
use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves the onboarding checklist for a user: combines the declared steps with
 * persisted manual completions and live auto-detection, and persists manual
 * completions / dismissal. Progress is per user, or per team when
 * `kinetix.onboarding.teams` is on.
 */
class OnboardingManager
{
    public function __construct(protected OnboardingStepRegistry $registry) {}

    public function for(Model $user): OnboardingData
    {
        $progress = $this->progressFor($user);
        $manual   = $progress->completed ?? [];

        $steps          = [];
        $completedCount = 0;

        foreach ($this->registry->all() as $step) {
            $completed = in_array($step->key, $manual, true) || $step->isAutoCompleted($user);

            if ($completed) {
                $completedCount++;
            }

            $steps[] = OnboardingStepData::fromStep($step, $completed, $user);
        }

        $total = count($steps);

        return new OnboardingData(
            steps: $steps,
            completedCount: $completedCount,
            total: $total,
            complete: $total > 0 && $completedCount === $total,
            dismissed: (bool) $progress->dismissed,
        );
    }

    public function complete(Model $user, string $stepKey): void
    {
        if (! $this->registry->has($stepKey)) {
            return;
        }

        $progress  = $this->progressFor($user);
        $completed = $progress->completed ?? [];

        if (! in_array($stepKey, $completed, true)) {
            $completed[]         = $stepKey;
            $progress->completed = array_values($completed);
            $progress->save();
        }
    }

    public function uncomplete(Model $user, string $stepKey): void
    {
        $progress  = $this->progressFor($user);
        $completed = $progress->completed ?? [];

        $progress->completed = array_values(array_filter($completed, static fn (string $k): bool => $k !== $stepKey));
        $progress->save();
    }

    public function dismiss(Model $user): void
    {
        $progress            = $this->progressFor($user);
        $progress->dismissed = true;
        $progress->save();
    }

    /**
     * `firstOrNew`, not `firstOrCreate`: `for()` is a pure read, and it now runs
     * on every Inertia response when `onboarding.share` is on — a row is written
     * only once the user actually ticks a step off or dismisses the checklist.
     */
    protected function progressFor(Model $user): OnboardingProgress
    {
        return OnboardingProgress::firstOrNew([
            'user_id' => $user->getKey(),
            'team_id' => $this->teamId($user),
        ]);
    }

    /**
     * The active team: the `{current_team}` segment when the call happens in a
     * request (so a page served for team B never writes team A's row), falling
     * back to the given user's `currentTeam` outside one — queued jobs and
     * console runs, where the user is explicit and there is no route.
     */
    protected function teamId(Model $user): int|string|null
    {
        if (! KinetixTeams::enabledFor('onboarding')) {
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
