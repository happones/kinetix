<?php

declare(strict_types=1);

namespace Happones\Kinetix\Dismissals;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One alert a user closed for good (or until `expires_at`).
 *
 * @property int|string  $id
 * @property int|string  $user_id
 * @property string      $key
 * @property Carbon|null $dismissed_at
 * @property Carbon|null $expires_at
 */
class Dismissal extends Model
{
    protected $table = 'kinetix_dismissals';

    protected $guarded = [];

    /**
     * Closes still in force: no expiry, or one still ahead.
     *
     * @param  Builder<static> $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(fn (Builder $live): Builder => $live
            ->whereNull('expires_at')
            ->orWhere('expires_at', '>', now()));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dismissed_at' => 'datetime',
            'expires_at'   => 'datetime',
        ];
    }
}
