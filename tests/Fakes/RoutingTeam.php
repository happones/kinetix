<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Fakes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RoutingTeam extends Model
{
    protected $table = 'teams';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsToMany<RoutingUser, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(RoutingUser::class, 'team_members', 'team_id', 'user_id');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
