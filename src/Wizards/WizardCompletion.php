<?php

declare(strict_types=1);

namespace Happones\Kinetix\Wizards;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Records that a user (optionally within a team) has completed a named wizard.
 *
 * @property int|string      $id
 * @property int|string      $user_id
 * @property int|string|null $team_id
 * @property string          $slug
 * @property Carbon|null     $completed_at
 */
class WizardCompletion extends Model
{
    protected $table = 'kinetix_wizard_completions';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }
}
