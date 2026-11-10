<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Fakes;

use Illuminate\Support\Facades\DB;

/**
 * A host's own notion of a team's people: its users and every client.
 */
class RoutingBothMembers
{
    /**
     * @return list<RoutingUser|RoutingClient>
     */
    public static function of(int|string|null $team): array
    {
        $users = RoutingUser::query()->whereIn('id', DB::table('team_members')->where('team_id', $team)->select('user_id'))->get()->all();

        return [...$users, ...RoutingClient::query()->get()->all()];
    }
}
