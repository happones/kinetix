<?php

declare(strict_types=1);

namespace Happones\Kinetix\Announcements;

use Happones\Kinetix\Support\Concerns\ScopedToTeam;
use Illuminate\Database\Eloquent\Builder;
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
    use ScopedToTeam;

    public static function kinetixTeamModule(): string
    {
        return 'announcements';
    }

    protected $table = 'kinetix_announcements';

    protected $guarded = [];

    /**
     * Live entries only: a `published_at` in the past. NULL is a draft, a
     * future one is scheduled.
     *
     * @param  Builder<static> $query
     * @return Builder<static>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

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
