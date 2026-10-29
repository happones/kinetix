<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use Happones\Kinetix\Data\FormFieldData;
use Happones\Kinetix\Data\InfolistEntryData;
use Illuminate\Support\Str;

/**
 * The query-string side of Tabs and Wizard: which param a group persists its
 * open tab/step in, and the key each child is named by — in the URL
 * (`?tab=security`, `?step=billing`) and by tours (`TourStep::tab()`).
 */
final class QueryStringKeys
{
    /**
     * The param a group persists in: an explicit name, nothing when switched
     * off (`false`), or — left unset — the default name while the config
     * switch is on. A published config predating the switch counts as on.
     */
    public static function param(string|bool|null $configured, string $default, string $configKey): ?string
    {
        if ($configured === false) {
            return null;
        }

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        if ($configured === true) {
            return $default;
        }

        return (bool) config($configKey, true) ? $default : null;
    }

    /**
     * Name each child: its explicit key, else the slug of its
     * heading, else `{fallback}-{position}` (a heading with nothing to slug).
     * Duplicates get `-2`, `-3`… so every key in the group is unique.
     *
     * @param array<int, FormFieldData|InfolistEntryData> $children
     * @param array<int, string|null>                     $explicitKeys same order as $children
     */
    public static function assign(array $children, array $explicitKeys, string $fallback): void
    {
        $taken = [];

        foreach (array_values($children) as $position => $child) {
            $base = $explicitKeys[$position] ?? null;
            $base = $base !== null && trim($base) !== '' ? trim($base) : Str::slug((string) $child->heading);

            if ($base === '') {
                $base = $fallback.'-'.($position + 1);
            }

            $key    = $base;
            $suffix = 2;

            while (isset($taken[$key])) {
                $key = $base.'-'.$suffix++;
            }

            $taken[$key]   = true;
            $child->urlKey = $key;
        }
    }
}
