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
     * Published files whose host copy differs from the package's — i.e. local
     * edits that the next `--force` publish will discard. Paths are relative to
     * the project root for readable output.
     *
     * @return array<int, string>
     */
    public static function drifted(): array
    {
        $drifted = [];

        foreach (static::map() as [$source, $target]) {
            if (is_file($source)) {
                if (static::differs($source, $target)) {
                    $drifted[] = static::relative($target);
                }

                continue;
            }

            if (! File::isDirectory($source) || ! File::isDirectory($target)) {
                continue;
            }

            foreach (File::allFiles($source) as $file) {
                $hostPath = $target.'/'.$file->getRelativePathname();

                if (static::differs($file->getPathname(), $hostPath)) {
                    $drifted[] = static::relative($hostPath);
                }
            }
        }

        sort($drifted);

        return $drifted;
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

    protected static function differs(string $source, string $target): bool
    {
        // Only files the host has actually adopted are compared.
        return File::exists($target) && md5_file($source) !== md5_file($target);
    }

    protected static function relative(string $path): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
