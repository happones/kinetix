<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

/**
 * Resolves team scoping per module with tri-state inheritance: a module's
 * `kinetix.{module}.teams` set to `true`/`false` wins; `null` (the default)
 * inherits the global `kinetix.teams`. One switch covers the whole suite,
 * per-module overrides stay possible (e.g. team-scoped app, personal billing).
 */
class KinetixTeams
{
    /**
     * Whether the given module scopes its data per team.
     */
    public static function enabledFor(string $module): bool
    {
        $value = config("kinetix.{$module}.teams");

        if ($value === null) {
            return (bool) config('kinetix.teams', false);
        }

        return (bool) $value;
    }
}
