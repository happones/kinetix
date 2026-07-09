<?php

declare(strict_types=1);

namespace Happones\Kinetix\Pdf;

use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Database\Eloquent\Model;

/**
 * Persisted settings of one PDF template (per team when `kinetix.pdf.teams`
 * — inheriting the global flag — is on).
 *
 * @property int                  $id
 * @property string               $key
 * @property int|string|null      $team_id
 * @property array<string, mixed> $settings
 */
class PdfTemplateSetting extends Model
{
    protected $table = 'kinetix_pdf_templates';

    protected $guarded = [];

    protected $casts = ['settings' => 'array'];

    /**
     * The stored settings for a template key in the current scope.
     *
     * @return array<string, mixed>
     */
    public static function for(string $key): array
    {
        $row = static::query()
            ->where('key', $key)
            ->where('team_id', static::scopeTeamId())
            ->first();

        if ($row === null) {
            return [];
        }

        return $row->settings ?? [];
    }

    /**
     * Persist (replace) the settings for a template key in the current scope.
     *
     * @param array<string, mixed> $settings
     */
    public static function put(string $key, array $settings): void
    {
        static::query()->updateOrCreate(
            ['key' => $key, 'team_id' => static::scopeTeamId()],
            ['settings' => $settings],
        );
    }

    protected static function scopeTeamId(): int|string|null
    {
        if (! KinetixTeams::enabledFor('pdf')) {
            return null;
        }

        return KinetixTeams::currentTeamKey();
    }
}
