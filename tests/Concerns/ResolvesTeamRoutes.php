<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Concerns;

use Illuminate\Routing\Route as RoutingRoute;

/**
 * Puts a team segment on the current request the way a real
 * `{current_team}` (or Billing's `{team}`) prefixed route does, so team
 * resolution can be tested without booting a full HTTP route.
 */
trait ResolvesTeamRoutes
{
    protected function withTeamSegment(int|string|object $team, string $param = 'current_team'): void
    {
        request()->setRouteResolver(function () use ($team, $param): RoutingRoute {
            $route = new RoutingRoute('GET', '{'.$param.'}/anything', []);
            $route->bind(request());
            $route->setParameter($param, is_object($team) ? $team : (string) $team);

            return $route;
        });
    }

    protected function withoutTeamSegment(): void
    {
        request()->setRouteResolver(fn (): RoutingRoute => (new RoutingRoute('GET', 'anything', []))->bind(request()));
    }
}
