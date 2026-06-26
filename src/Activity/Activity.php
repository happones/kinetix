<?php

declare(strict_types=1);

namespace Happones\Kinetix\Activity;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A single activity entry — the native store behind the Activity module. Written
 * via {@see ActivityLogger} / the {@see KinetixActivity} facade (or the
 * {@see Concerns\LogsKinetixActivity} model trait), read back through the logger's
 * paginated query. `properties` holds the `{ old, attributes }` diff for updates.
 *
 * @property int|string                $id
 * @property int|string|null           $team_id
 * @property string|null               $log_name
 * @property string|null               $event
 * @property string|null               $description
 * @property string|null               $subject_type
 * @property int|string|null           $subject_id
 * @property string|null               $causer_type
 * @property int|string|null           $causer_id
 * @property array<string, mixed>|null $properties
 * @property Carbon|null               $created_at
 */
class Activity extends Model
{
    protected $table = 'kinetix_activity';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function causer(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
