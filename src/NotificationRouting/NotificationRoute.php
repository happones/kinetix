<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting;

use Happones\Kinetix\Support\Concerns\ScopedToTeam;
use Happones\Kinetix\Support\Memo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One rule of a team's notification routing: a type goes to an audience on a
 * set of channels. Always read through `forCurrentTeam()` (requests) or an
 * explicit `team_id` (the queued fan-out, which never infers the team).
 *
 * @property int|string      $id
 * @property int|string|null $team_id
 * @property string          $type
 * @property AudienceType    $audience_type
 * @property string          $audience_id
 * @property list<string>    $channels
 * @property Carbon|null     $created_at
 */
class NotificationRoute extends Model
{
    use ScopedToTeam;

    protected $table = 'kinetix_notification_routes';

    protected $guarded = [];

    /**
     * A rule written or removed drops the memoized rules of the request.
     */
    protected static function booted(): void
    {
        $flush = static fn () => Memo::flush(AudienceResolver::MEMO);

        static::saved($flush);
        static::deleted($flush);
    }

    public static function kinetixTeamModule(): string
    {
        return 'notification_routing';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience_type' => AudienceType::class,
            'channels'      => 'array',
        ];
    }
}
