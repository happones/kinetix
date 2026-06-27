<?php

declare(strict_types=1);

namespace Happones\Kinetix\Announcements;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row per user recording when they last opened the announcements feed.
 *
 * @property int|string  $id
 * @property int|string  $user_id
 * @property Carbon|null $seen_at
 */
class AnnouncementView extends Model
{
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
