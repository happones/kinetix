<?php

declare(strict_types=1);

namespace Happones\Kinetix\Notifications;

use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Narrows a user's notifications to the ones the current team shows.
 *
 * With team-scoped notifications on, the bell lists only notifications
 * stamped with the active team ({@see Notification::team()}) plus global,
 * unstamped ones — so "mark all as read" and "clear all" must touch exactly
 * those, never another team's. A route prefix alone is not data isolation:
 * this is the filter.
 *
 * @internal
 */
final class NotificationScope
{
    /**
     * @template TQuery of Builder|Relation
     *
     * @param  TQuery $query
     * @return TQuery
     */
    public static function apply(Builder|Relation $query): Builder|Relation
    {
        if (! KinetixTeams::enabledFor('notifications')) {
            return $query;
        }

        $teamKey = KinetixTeams::currentTeamKey();

        $query->where(static function (BuilderContract $scoped) use ($teamKey): void {
            $scoped->whereNull('data->team');

            if ($teamKey !== null) {
                $scoped->orWhere('data->team', $teamKey);
            }
        });

        return $query;
    }
}
