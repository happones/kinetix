<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting;

use Happones\Kinetix\Support\Concerns\ScopedToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A team's named group of members, an audience a route can point at.
 *
 * @property int|string      $id
 * @property int|string|null $team_id
 * @property string          $name
 * @property string|null     $description
 */
class NotificationGroup extends Model
{
    use ScopedToTeam;

    protected $table = 'kinetix_notification_groups';

    protected $guarded = [];

    public static function kinetixTeamModule(): string
    {
        return 'notification_routing';
    }

    /**
     * @return HasMany<NotificationGroupMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(NotificationGroupMember::class, 'group_id');
    }
}
