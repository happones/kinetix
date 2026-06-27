<?php

declare(strict_types=1);

namespace Happones\Kinetix\Accessibility;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per user holding their accessibility preferences (JSON).
 *
 * @property int|string           $id
 * @property int|string           $user_id
 * @property array<string, mixed> $preferences
 */
class AccessibilityPreference extends Model
{
    protected $table = 'kinetix_accessibility';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'preferences' => 'array',
        ];
    }
}
