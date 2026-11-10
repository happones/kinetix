<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Fakes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * A team member of the notification routing tests: notifiable, with roles,
 * a `teams` relation and a `currentTeam` the test can point anywhere.
 */
class RoutingUser extends Authenticatable
{
    use HasRoles;
    use Notifiable;

    /** The team `currentTeam` answers with; null = none. */
    public static int|string|null $activeTeam = null;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected string $guard_name = 'web';

    /**
     * @return BelongsToMany<RoutingTeam, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(RoutingTeam::class, 'team_members', 'user_id', 'team_id');
    }

    public function getCurrentTeamAttribute(): ?Model
    {
        return self::$activeTeam === null ? null : RoutingTeam::query()->find(self::$activeTeam);
    }
}
