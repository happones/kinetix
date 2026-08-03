<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use Illuminate\Support\Facades\File;

/**
 * Maps the package's publishable sources to where they land in the host, so
 * Kinetix can tell whether an adopted copy has **local edits**.
 *
 * Published files are vendor-managed: `kinetix:upgrade` (wired into composer's
 * `post-autoload-dump`) re-publishes them with `--force`, so an edit made in
 * `resources/js/components/kinetix/…` disappears on the next `composer install`
 * — silently, which is how a fix "stops existing" in CI without anyone touching
 * it. Detecting the drift lets the command name the files it just overwrote
 * instead of leaving it to be discovered by accident.
 */
class PublishedFiles
{
    /**
     * Source → target pairs, mirroring the `kinetix-components` /
     * `kinetix-skills` publish maps. Files, not tags, so a host file that the
     * package doesn't ship (their own composables, their own skills) is never
     * inspected.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function map(): array
    {
        $root = dirname(__DIR__, 2);

        return [
            [$root.'/resources/js/components', resource_path('js/components/kinetix')],
            [$root.'/resources/js/composables', resource_path('js/composables')],
            [$root.'/resources/js/stores', resource_path('js/stores')],
            [$root.'/resources/js/types/kinetix.ts', resource_path('js/types/kinetix.ts')],
            [$root.'/resources/boost/skills', base_path((string) config('kinetix.skills_path', '.claude/skills'))],
        ];
    }

    /**
     * Where the hashes of the last publish are recorded. Lives in storage (not
     * committed): drift detection is about edits on THIS machine's disk.
     */
    public static function manifestPath(): string
    {
        return storage_path('app/kinetix-published-manifest.json');
    }

    /**
     * The hashes recorded by the last publish, or null when no baseline exists
     * yet (fresh checkout, or first run after this feature shipped).
     *
     * @return array<string, string>|null relative target path => md5
     */
    public static function recordedHashes(): ?array
    {
        $path = static::manifestPath();

        if (! File::exists($path)) {
            return null;
        }

        $decoded = json_decode((string) File::get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Record the hashes of the published files as they exist on disk right
     * now — called after a publish, so the manifest describes exactly what
     * Kinetix wrote. The next drift check compares against THIS, which is what
     * separates "you edited it" from "the package shipped a new version".
     */
    public static function record(): void
    {
        $hashes = [];

        foreach (static::eachPublishedTarget() as $target) {
            $hashes[static::relative($target)] = (string) md5_file($target);
        }

        File::ensureDirectoryExists(dirname(static::manifestPath()));
        File::put(
            static::manifestPath(),
            (string) json_encode($hashes, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Published files whose host copy differs from what the LAST PUBLISH wrote
     * — i.e. genuine local edits that the next `--force` publish will discard.
     *
     * Comparing against the recorded baseline (not the package's new sources)
     * is the point: after a `composer update`, every file changed UPSTREAM
     * differs from the new sources, and reporting those as "local edits" turns
     * a warning that should be rare into noise. Without a baseline (first run)
     * nothing is claimed; the publish records one.
     *
     * @return array<int, string>
     */
    public static function drifted(): array
    {
        $recorded = static::recordedHashes();

        if ($recorded === null) {
            return [];
        }

        $drifted = [];

        foreach (static::eachPublishedTarget() as $target) {
            $relative = static::relative($target);
            $baseline = $recorded[$relative] ?? null;

            if ($baseline !== null && md5_file($target) !== $baseline) {
                $drifted[] = $relative;
            }
        }

        sort($drifted);

        return $drifted;
    }

    /**
     * Every mapped published file the host has actually adopted (exists on
     * disk AND is still shipped by the package).
     *
     * @return iterable<int, string> absolute target paths
     */
    protected static function eachPublishedTarget(): iterable
    {
        foreach (static::map() as [$source, $target]) {
            if (is_file($source)) {
                if (File::exists($target)) {
                    yield $target;
                }

                continue;
            }

            if (! File::isDirectory($source) || ! File::isDirectory($target)) {
                continue;
            }

            foreach (File::allFiles($source) as $file) {
                $hostPath = $target.'/'.$file->getRelativePathname();

                if (File::exists($hostPath)) {
                    yield $hostPath;
                }
            }
        }
    }

    /**
     * The compiled Vue i18n bundles present in the host.
     *
     * `vue-i18n:generate` writes `--format=ts` by default while the generator's
     * published config points `jsFile` at a `.js` path, so both can exist — and
     * Vite resolves `.js` before `.ts`, meaning the bundle that actually gets
     * compiled is the one nothing refreshes. New translation keys then land in
     * the `.ts` and never reach the UI.
     *
     * @return array<int, string> Relative paths, at most one of which should exist.
     */
    public static function i18nBundles(): array
    {
        $found = [];

        foreach (['js', 'ts'] as $extension) {
            $path = resource_path('js/vue-i18n-locales.generated.'.$extension);

            if (File::exists($path)) {
                $found[] = static::relative($path);
            }
        }

        return $found;
    }

    /**
     * A `types/index.ts` left over from before Kinetix published to
     * `types/kinetix.ts` — recognizable because it is the package's own file,
     * which means it replaced the starter kit's barrel.
     */
    public static function legacyTypesBarrel(): ?string
    {
        $path = resource_path('js/types/index.ts');

        if (! File::exists($path)) {
            return null;
        }

        $contents = (string) File::get($path);

        // The package's declarations, with none of a host barrel's re-exports.
        return str_contains($contents, 'interface KinetixAction') && ! str_contains($contents, 'export * from')
            ? static::relative($path)
            : null;
    }

    protected static function relative(string $path): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
