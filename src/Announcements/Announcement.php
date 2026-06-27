<?php

declare(strict_types=1);

namespace Happones\Kinetix\Announcements;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A product announcement / "what's new" entry. Only entries with a past
 * `published_at` are shown; a null value is a draft.
 *
 * @property int|string  $id
 * @property string      $title
 * @property string      $body
 * @property string      $level
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 */
class Announcement extends Model
{
    protected $table = 'kinetix_announcements';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }
}
