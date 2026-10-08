<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationPreferences;

use Happones\Kinetix\Support\Concerns\OwnedByModelType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row per notifiable holding their notification opt-outs as a nested map:
 * `{ type: { channel: bool } }`. An absent type/channel defaults to enabled.
 *
 * A row belongs to its notifiable by key AND model type, so a `Client` #1
 * never reads a `User` #1's choices. Always query through {@see ownedBy()}.
 *
 * @property int|string                              $id
 * @property int|string                              $user_id
 * @property string|null                             $notifiable_type
 * @property array<string, array<string, bool>>|null $preferences
 * @property Carbon|null                             $created_at
 */
class NotificationPreference extends Model
{
    use OwnedByModelType;

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

    protected static function ownerTypeColumn(): string
    {
        return 'notifiable_type';
    }
}
