<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tours;

use Illuminate\Database\Eloquent\Model;

/**
 * A user's per-tour "seen" record for the `database` tours driver (the
 * `local` driver keeps this in the browser's localStorage instead).
 */
class TourState extends Model
{
    protected $table = 'kinetix_tour_state';

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'tour_id', 'seen_at'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'seen_at' => 'datetime',
    ];
}
