<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use Illuminate\Support\Facades\File;

/**
 * Keeps the published agent skills out of Tailwind's source scan.
 *
 * Tailwind v4 scans every file in the project that git doesn't ignore, so the
 * Markdown skills Kinetix publishes for coding agents (`.claude/skills`,
 * `.agents/skills`, …) end up in the class scan: every class name quoted in a
 * code sample becomes CSS the app ships and never uses. Tailwind 4.1 added
 * `@source not`, so `kinetix:install` and `kinetix:upgrade` write one such line
 * per skills directory into `resources/css/app.css`, and `kinetix:doctor`
 * checks they are still there.
 *
 * Only directories that hold a `kinetix-*` skill are excluded: a host's own
 * skills directory without Kinetix's in it is none of Kinetix's business.
 * `kinetix.tailwind.exclude_agent_skills` turns the whole thing off.
 */
class TailwindSourceExclusions
{
    /** The first Tailwind release that understands `@source not`. */
    public const MIN_TAILWIND = '4.1.0';

    /**
     * The statements the exclusions are written after.
     *
     * @var list<string>
     */
    protected const DIRECTIVES = ['@import', '@source'];

    /** Written once, above the first exclusion line. */
    public const MARKER = '/* Kinetix: agent skills are documentation, not markup — keep them out of Tailwind\'s scan */';

    /**
     * Skills directories looked at besides `kinetix.skills_path`.
     *
     * @var list<string>
     */
    protected const KNOWN_SKILLS_PATHS = ['.claude/skills', '.agents/skills'];

    /**
     * Why the exclusions can't or needn't be written, or null when they can.
     */
    public static function skipReason(): ?string
    {
        if (! (bool) config('kinetix.tailwind.exclude_agent_skills', true)) {
            return 'turned off (kinetix.tailwind.exclude_agent_skills)';
        }

        if (self::directories() === []) {
            return 'no published Kinetix skills to exclude';
        }

        if (! File::exists(self::stylesheet())) {
            return 'resources/css/app.css not found';
        }

        $version = self::tailwindVersion();

        if ($version === null) {
            return 'Tailwind is not installed in node_modules';
        }

        if (version_compare($version, self::MIN_TAILWIND, '<')) {
            return "Tailwind {$version} predates `@source not` (".self::MIN_TAILWIND.')';
        }

        return null;
    }

    /**
     * The `@source not` paths the stylesheet should carry, relative to it.
     *
     * @return list<string>
     */
    public static function expected(): array
    {
        $from = dirname(self::stylesheet());

        return array_map(
            static fn (string $directory): string => self::relativePath($from, $directory),
            self::directories(),
        );
    }

    /**
     * The expected paths the stylesheet doesn't exclude yet (an exclusion of a
     * parent directory covers its children).
     *
     * @return list<string>
     */
    public static function missing(): array
    {
        $path = self::stylesheet();

        if (! File::exists($path)) {
            return self::expected();
        }

        $excluded = self::excludedPaths((string) File::get($path));

        return array_values(array_filter(
            self::expected(),
            static fn (string $expected): bool => ! self::isCovered($expected, $excluded),
        ));
    }

    /**
     * The 1-based lines of the stylesheet where an `@import` / `@source`
     * statement sits inside a block (`@theme { … }`), which Tailwind rejects.
     *
     * @return list<int>
     */
    public static function misplaced(): array
    {
        $path = self::stylesheet();

        if (! File::exists($path)) {
            return [];
        }

        return array_map(
            static fn (int $line): int => $line + 1,
            StylesheetLines::nestedDirectives((string) File::get($path), self::DIRECTIVES),
        );
    }

    /**
     * Why the stylesheet can't be edited safely, or null when it can: it
     * already has a directive inside a block, or no line after its last
     * top-level `@import` / `@source` statement is a safe place.
     */
    public static function placementProblem(): ?string
    {
        if (self::misplaced() !== []) {
            return 'resources/css/app.css has an @import/@source inside a block';
        }

        $css = (string) File::get(self::stylesheet());

        if (StylesheetLines::insertAfterLast($css, [''], self::DIRECTIVES) === null) {
            return 'no safe place after the @import/@source lines of resources/css/app.css';
        }

        return null;
    }

    /**
     * Write the missing exclusions at the top level, right after the
     * stylesheet's last complete `@import` / `@source` statement. Writes
     * nothing when skipReason() or placementProblem() gives a reason, so an
     * unattended upgrade never edits a stylesheet it can't place them in.
     * Idempotent.
     *
     * @return list<string> The paths added.
     */
    public static function ensure(): array
    {
        if (self::skipReason() !== null) {
            return [];
        }

        $missing = self::missing();

        if ($missing === [] || self::placementProblem() !== null) {
            return [];
        }

        $path     = self::stylesheet();
        $existing = (string) File::get($path);
        $insert   = array_map(static fn (string $relative): string => "@source not '{$relative}';", $missing);

        if (! str_contains($existing, self::MARKER)) {
            array_unshift($insert, self::MARKER);
        }

        $updated = StylesheetLines::insertAfterLast($existing, $insert, self::DIRECTIVES);

        if ($updated === null) {
            return [];
        }

        File::put($path, $updated);

        return $missing;
    }

    /**
     * The skills directories that exist and hold at least one `kinetix-*`
     * skill, as absolute paths, without duplicates.
     *
     * @return list<string>
     */
    public static function directories(): array
    {
        $configured = (string) config('kinetix.skills_path', '.claude/skills');
        $found      = [];

        foreach ([$configured, ...self::KNOWN_SKILLS_PATHS] as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '') {
                continue;
            }

            $absolute = self::normalize(str_starts_with($candidate, '/') ? $candidate : base_path($candidate));

            if (in_array($absolute, $found, true) || ! File::isDirectory($absolute)) {
                continue;
            }

            if ((File::glob($absolute.'/kinetix-*', GLOB_ONLYDIR) ?: []) === []) {
                continue;
            }

            $found[] = $absolute;
        }

        return $found;
    }

    /**
     * The host's main stylesheet.
     */
    public static function stylesheet(): string
    {
        return resource_path('css/app.css');
    }

    /**
     * The installed Tailwind version, or null when node_modules has none.
     */
    public static function tailwindVersion(): ?string
    {
        $manifest = base_path('node_modules/tailwindcss/package.json');

        if (! File::exists($manifest)) {
            return null;
        }

        $version = json_decode((string) File::get($manifest), true)['version'] ?? null;

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * The paths a stylesheet already excludes with `@source not`.
     *
     * @return list<string>
     */
    protected static function excludedPaths(string $css): array
    {
        preg_match_all('/@source\s+not\s+([\'"])(.+?)\1/', $css, $matches);

        return array_map(static fn (string $path): string => rtrim($path, '/'), $matches[2]);
    }

    /**
     * Whether `$path` is one of `$excluded` or sits inside one of them.
     *
     * @param list<string> $excluded
     */
    protected static function isCovered(string $path, array $excluded): bool
    {
        foreach ($excluded as $exclusion) {
            if ($path === $exclusion || str_starts_with($path, $exclusion.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * `$to` relative to the directory `$from` (both absolute), e.g.
     * `../../.claude/skills` from `resources/css`.
     */
    protected static function relativePath(string $from, string $to): string
    {
        $segments = static fn (string $path): array => array_values(array_filter(
            explode('/', self::normalize($path)),
            static fn (string $part): bool => $part !== '',
        ));
        $fromParts = $segments($from);
        $toParts   = $segments($to);

        $common = 0;

        while (isset($fromParts[$common], $toParts[$common]) && $fromParts[$common] === $toParts[$common]) {
            $common++;
        }

        $up = array_fill(0, count($fromParts) - $common, '..');

        $relative = implode('/', [...$up, ...array_slice($toParts, $common)]);

        return $relative === '' ? '.' : $relative;
    }

    /**
     * Forward slashes, no trailing slash, `.` segments dropped.
     */
    protected static function normalize(string $path): string
    {
        $path  = str_replace('\\', '/', $path);
        $parts = array_filter(explode('/', $path), static fn (string $part): bool => $part !== '.');

        return rtrim(implode('/', $parts), '/');
    }
}
