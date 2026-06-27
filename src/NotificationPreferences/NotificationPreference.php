<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationPreferences;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row per user holding their notification opt-outs as a nested map:
 * `{ type: { channel: bool } }`. An absent type/channel defaults to enabled.
 *
 * @property int|string                              $id
 * @property int|string                              $user_id
 * @property array<string, array<string, bool>>|null $preferences
 * @property Carbon|null                             $created_at
 */
class NotificationPreference extends Model
{
    protected $table = 'kinetix_notification_preferences';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'preferences' => 'array',
        ];
    }
}
