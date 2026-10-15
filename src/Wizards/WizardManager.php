<?php

declare(strict_types=1);

namespace Happones\Kinetix\Wizards;

use Happones\Kinetix\Support\ConfigCallback;
use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Persists and queries per-user (optionally per-team) completion of named
 * wizards. Backs the `kinetix.wizard:<slug>` gating middleware.
 *
 * A gate (`kinetix.wizards.gates.<slug>`) is either the route name to send
 * users to, or `['route' => ..., 'scope' => 'user'|'team', 'completed' => callable]`:
 * `scope: team` is completed once for the whole team, and `completed` decides
 * from your data instead of a stored row (the self-service endpoint then
 * can't open the gate).
 */
class WizardManager
{
    public function hasCompleted(Model $user, string $slug): bool
    {
        $gate = $this->gate($slug);

        if ($gate['completed'] !== null) {
            return (bool) ($gate['completed'])($user, $this->teamId($user));
        }

        return $this->rows($user, $slug, $gate['scope'])
            ->whereNotNull('completed_at')
            ->exists();
    }

    /**
     * The gate for a slug, read from `kinetix.wizards.gates`.
     *
     * @return array{route: string|null, scope: 'user'|'team', completed: callable|null}
     */
    public function gate(string $slug): array
    {
        $config = config("kinetix.wizards.gates.{$slug}");

        if (! is_array($config)) {
            return [
                'route'     => is_string($config) && $config !== '' ? $config : null,
                'scope'     => 'user',
                'completed' => null,
            ];
        }

        $route = $config['route'] ?? null;

        return [
            'route'     => is_string($route) && $route !== '' ? $route : null,
            'scope'     => ($config['scope'] ?? 'user') === 'team' ? 'team' : 'user',
            'completed' => ConfigCallback::resolve($config['completed'] ?? null),
        ];
    }

    /**
     * Whether your data, not a stored row, decides the slug's completion.
     */
    public function completesFromData(string $slug): bool
    {
        return $this->gate($slug)['completed'] !== null;
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
        $this->rows($user, $slug, $this->gate($slug)['scope'])->delete();
    }

    /**
     * The completion rows that count: the user's own, or with `scope: team`
     * any member's in the team.
     *
     * @return Builder<WizardCompletion>
     */
    protected function rows(Model $user, string $slug, string $scope): Builder
    {
        $team = $this->teamId($user);

        return WizardCompletion::query()
            ->where('slug', $slug)
            ->where('team_id', $team)
            ->when($scope !== 'team' || $team === null, fn (Builder $query) => $query->where('user_id', $user->getKey()));
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
