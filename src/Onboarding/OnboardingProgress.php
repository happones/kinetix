<?php

declare(strict_types=1);

namespace Happones\Kinetix\Onboarding;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row per user (optionally per team) holding the manually-completed step
 * keys and whether the checklist has been dismissed. Auto-detected steps are
 * computed live and never stored here.
 *
 * @property int|string         $id
 * @property int|string         $user_id
 * @property int|string|null    $team_id
 * @property array<int, string> $completed
 * @property bool               $dismissed
 * @property Carbon|null        $created_at
 */
class OnboardingProgress extends Model
{
    protected $table = 'kinetix_onboarding';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'completed' => 'array',
            'dismissed' => 'boolean',
        ];
    }
}
