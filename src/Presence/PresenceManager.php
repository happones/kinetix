<?php

declare(strict_types=1);

namespace Happones\Kinetix\Presence;

use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves the presence channel (team-aware) and the member payload broadcast to
 * other users on it, for the <KinetixOnlineUsers> facepile and online dots.
 */
class PresenceManager
{
    /**
     * The presence channel name for the current scope. Suffixed with the active
     * team id when teams are on, so each team gets its own presence room.
     */
    public function channelName(): string
    {
        $base = (string) config('kinetix.presence.channel', 'kinetix-presence');

        if (config('kinetix.teams', false)) {
            // Primary key (route segments may be slugs) so the channel name is
            // stable regardless of how the host routes teams.
            $team = KinetixTeams::currentTeamKey();

            if ($team) {
                return "{$base}.{$team}";
            }
        }

        return $base;
    }

    /**
     * The channel-authorization pattern registered with Broadcast. Includes a
     * `{team}` placeholder when teams are on.
     */
    public function channelPattern(): string
    {
        $base = (string) config('kinetix.presence.channel', 'kinetix-presence');

        return config('kinetix.teams', false) ? "{$base}.{team}" : $base;
    }

    /**
     * The member payload shared with other users on the presence channel.
     *
     * @return array{id: int|string|null, name: string, avatar: string|null}
     */
    public function memberData(Model $user): array
    {
        $avatarAttribute = config('kinetix.presence.avatar_attribute');
        $avatar          = is_string($avatarAttribute) && $avatarAttribute !== ''
            ? $user->getAttribute($avatarAttribute)
            : null;

        return [
            'id'     => $user->getKey(),
            'name'   => (string) $user->getAttribute(config('kinetix.presence.name_attribute', 'name')),
            'avatar' => is_string($avatar) ? $avatar : null,
        ];
    }

    /**
     * The shared-prop payload for the frontend.
     *
     * @return array{enabled: bool, channel: string|null}
     */
    public function state(): array
    {
        if (! config('kinetix.presence.enabled', false)) {
            return ['enabled' => false, 'channel' => null];
        }

        return ['enabled' => true, 'channel' => $this->channelName()];
    }
}
