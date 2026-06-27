<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tags;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A reusable tag, optionally scoped to a team. Attached to models polymorphically
 * through the `kinetix_taggables` pivot.
 *
 * @property int|string      $id
 * @property int|string|null $team_id
 * @property string          $name
 * @property string          $slug
 * @property Carbon|null     $created_at
 */
class Tag extends Model
{
    protected $table = 'kinetix_tags';

    protected $guarded = [];
}
