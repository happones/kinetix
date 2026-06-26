<?php

declare(strict_types=1);

namespace Happones\Kinetix\Settings;

use Illuminate\Database\Eloquent\Model;

/**
 * The persisted store behind the Settings module. One row per `{key}` per scope
 * (`team_id` null = global). `value` holds a JSON-encoded payload, optionally
 * encrypted (`encrypted = true`). Read/write through {@see SettingsManager} /
 * the {@see KinetixSettings} facade rather than this model directly.
 *
 * @property int|string      $id
 * @property int|string|null $team_id
 * @property string          $key
 * @property string|null     $value
 * @property bool            $encrypted
 */
class Setting extends Model
{
    protected $table = 'kinetix_settings';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'encrypted' => 'boolean',
        ];
    }
}
