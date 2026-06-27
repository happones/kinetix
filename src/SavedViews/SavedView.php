<?php

declare(strict_types=1);

namespace Happones\Kinetix\SavedViews;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A user's saved table preset: a named snapshot of a table's
 * search/filters/sort/perPage/visible-columns state, scoped to a `view_key`.
 *
 * @property int|string           $id
 * @property int|string           $user_id
 * @property int|string|null      $team_id
 * @property string               $view_key
 * @property string               $name
 * @property array<string, mixed> $state
 * @property bool                 $is_default
 * @property Carbon|null          $created_at
 */
class SavedView extends Model
{
    protected $table = 'kinetix_saved_views';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state'      => 'array',
            'is_default' => 'boolean',
        ];
    }
}
