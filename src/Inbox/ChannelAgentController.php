<?php

declare(strict_types=1);

namespace Happones\Kinetix\Inbox;

use Happones\Kinetix\Channels\Channel;
use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Who answers a line, gated by `channels.manage`: the agents who see its
 * conversations in the inbox (members with `conversations.view_all` or
 * `channels.manage` see every line anyway).
 */
class ChannelAgentController
{
    public function __construct(protected InboxManager $inbox) {}

    public function show(Request $request): JsonResponse
    {
        $channel = $this->find($request);

        return response()->json([
            'agents'  => DB::table('kinetix_channel_agents')->where('channel_id', $channel->getKey())->pluck('user_id')->values(),
            'members' => $this->inbox->members($channel->team_id)
                ->map(fn (Model $user): array => [
                    'id'      => $user->getKey(),
                    'name'    => $this->inbox->userName($user),
                    'seesAll' => $this->inbox->seesAll($user),
                ])
                ->values(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $channel = $this->find($request);
        $ids     = array_map('strval', (array) ($request->validate(['user_ids' => ['present', 'array']])['user_ids']));
        $members = $this->inbox->members($channel->team_id)->map(static fn (Model $user): string => (string) $user->getKey())->all();

        if (array_diff($ids, $members) !== []) {
            throw ValidationException::withMessages(['user_ids' => __('kinetix::kinetix.inbox_error_assignee')]);
        }

        DB::transaction(function () use ($channel, $ids): void {
            DB::table('kinetix_channel_agents')->where('channel_id', $channel->getKey())->delete();
            DB::table('kinetix_channel_agents')->insert(array_map(static fn (string $id): array => [
                'channel_id' => $channel->getKey(),
                'user_id'    => $id,
                'created_at' => now(),
                'updated_at' => now(),
            ], array_values(array_unique($ids))));
        });

        return $this->show($request);
    }

    protected function find(Request $request): Channel
    {
        Gate::authorize('channels.manage');

        return Channel::query()
            ->where('team_id', KinetixTeams::keyFor('channels'))
            ->whereKey($request->route('channel'))
            ->firstOrFail();
    }
}
