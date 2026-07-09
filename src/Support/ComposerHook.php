<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

/**
 * Idempotently registers a script on a composer.json event. Composer only
 * runs scripts from the ROOT composer.json (never from packages), so hooks
 * like `@php artisan kinetix:upgrade` must be written into the host app —
 * this is the same mechanism Filament's installer uses.
 */
class ComposerHook
{
    /**
     * Add $script to composer.json's $event scripts once. Returns true when
     * the file was modified, false when it was already present or unreadable.
     */
    public static function ensure(string $composerJsonPath, string $event, string $script): bool
    {
        if (! is_file($composerJsonPath)) {
            return false;
        }

        $composer = json_decode((string) file_get_contents($composerJsonPath), true);

        if (! is_array($composer)) {
            return false;
        }

        $scripts = (array) ($composer['scripts'][$event] ?? []);

        // Normalize a single-string script definition to a list.
        if ($scripts !== [] && array_is_list($scripts) === false) {
            return false; // Unexpected shape — leave the file untouched.
        }

        if (in_array($script, $scripts, true)) {
            return false;
        }

        $scripts[]                   = $script;
        $composer['scripts'][$event] = $scripts;

        file_put_contents(
            $composerJsonPath,
            json_encode($composer, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        return true;
    }
}
