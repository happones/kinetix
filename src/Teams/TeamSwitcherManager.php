<?php

declare(strict_types=1);

namespace Happones\Kinetix\Teams;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * Resolves the active user's teams (and a ready-made switch URL for each) for
 * the <KinetixTeamSwitcher>. Kinetix does not own the Team model: it reads the
 * teams by the convention configured in `kinetix.team_switcher` and delegates
 * the actual switch to the host's route, so it stays model-agnostic.
 */
class TeamSwitcherManager
{
    /**
     * The shared-prop payload: the user's teams (each with a switch URL), the
     * current team, and an optional "create team" URL.
     *
     * @return array{
     *     enabled: bool,
     *     teams: array<int, array{id: int|string, key: mixed, name: string, url: string|null, current: bool}>,
     *     current: array{id: int|string, name: string}|null,
     *     createUrl: string|null,
     *     switchMethod: string
     * }
     */
    public function payload(): array
    {
        $user = auth()->user();

        if (! config('kinetix.team_switcher.enabled', false) || ! $user instanceof Model) {
            return ['enabled' => false, 'teams' => [], 'current' => null, 'createUrl' => null, 'switchMethod' => 'get'];
        }

        $current    = $this->currentTeam($user);
        $currentKey = $current?->getKey();

        $teams = $this->teamsFor($user)
            ->map(fn (Model $team): array => [
                'id' => $team->getKey(),
                // The team in a URL (`/{key}/...`): what a switch that keeps
                // the page swaps in.
                'key'     => $team->getRouteKey(),
                'name'    => (string) $team->getAttribute($this->nameAttribute()),
                'url'     => $this->switchUrl($team),
                'current' => $team->getKey() === $currentKey,
            ])
            ->values()
            ->all();

        return [
            'enabled' => true,
            'teams'   => $teams,
            'current' => $current instanceof Model
                ? ['id' => $current->getKey(), 'name' => (string) $current->getAttribute($this->nameAttribute())]
                : null,
            'createUrl' => $this->createUrl(),
            // How to visit a switch URL: the starter-kit's switch route is a
            // POST, and a GET to it is a 405.
            'switchMethod' => $this->switchMethod(),
        ];
    }

    /**
     * The collection of teams the user belongs to.
     *
     * @return Collection<int, Model>
     */
    protected function teamsFor(Model $user): Collection
    {
        $relation = config('kinetix.team_switcher.teams_relation', 'teams');
        $teams    = $user->getAttribute($relation);

        return collect($teams)->filter(fn ($team): bool => $team instanceof Model)->values();
    }

    protected function currentTeam(Model $user): ?Model
    {
        $relation = config('kinetix.team_switcher.current_relation', 'currentTeam');
        $team     = $user->getAttribute($relation);

        return $team instanceof Model ? $team : null;
    }

    protected function switchUrl(Model $team): ?string
    {
        $route = config('kinetix.team_switcher.switch_route', 'teams.switch');

        return is_string($route) && Route::has($route)
            ? route($route, $team->getRouteKey())
            : null;
    }

    /**
     * The switch route's HTTP verb, lower-case: `get` when it takes one,
     * else the first it declares (`post`, `put`, `patch`).
     */
    protected function switchMethod(): string
    {
        $route = config('kinetix.team_switcher.switch_route', 'teams.switch');
        $route = is_string($route) ? Route::getRoutes()->getByName($route) : null;

        if ($route === null || in_array('GET', $route->methods(), true)) {
            return 'get';
        }

        return strtolower($route->methods()[0]);
    }

    protected function createUrl(): ?string
    {
        $route = config('kinetix.team_switcher.create_route');

        return is_string($route) && Route::has($route) ? route($route) : null;
    }

    protected function nameAttribute(): string
    {
        return (string) config('kinetix.team_switcher.name_attribute', 'name');
    }
}
