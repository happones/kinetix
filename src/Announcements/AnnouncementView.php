<?php

declare(strict_types=1);

namespace Happones\Kinetix\Announcements;

use Happones\Kinetix\Support\Concerns\ScopedToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * When a user last opened the announcements feed — one row per (user, team),
 * plus a `team_id` NULL row holding the read state of the platform-wide
 * entries. Per-team, because a user in two teams reading team A's feed must
 * not clear team B's unread badge.
 *
 * @property int|string      $id
 * @property int|string      $user_id
 * @property int|string|null $team_id
 * @property Carbon|null     $seen_at
 */
class AnnouncementView extends Model
{
    use ScopedToTeam;

    public static function kinetixTeamModule(): string
    {
        return 'announcements';
    }

    protected $table = 'kinetix_announcement_views';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seen_at' => 'datetime',
        ];
    }
}
