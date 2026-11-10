<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting;

use Illuminate\Database\Eloquent\Model;

/**
 * A member of a group, by model type and key. Reached only through its
 * team-scoped {@see NotificationGroup}.
 *
 * @property int|string $id
 * @property int|string $group_id
 * @property string     $user_type
 * @property int|string $user_id
 */
class NotificationGroupMember extends Model
{
    protected $table = 'kinetix_notification_group_members';

    protected $guarded = [];
}
