<?php

declare(strict_types=1);

namespace Happones\Kinetix\Announcements;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row per (user, announcement) the user has closed from the banner. The
 * feed's single "last seen" timestamp can't express this: closing one banner
 * must hide that entry only, not mark everything read.
 *
 * @property int|string  $id
 * @property int|string  $user_id
 * @property int         $announcement_id
 * @property Carbon|null $dismissed_at
 */
class AnnouncementDismissal extends Model
{
    protected $table = 'kinetix_announcement_dismissals';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dismissed_at' => 'datetime',
        ];
    }
}
