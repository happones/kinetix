<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use Illuminate\Support\Facades\File;

/**
 * The classes the registries discover by scanning a directory (Resources for
 * the permission matrix, Spotlight sources, Reports Center reports), cached in
 * `bootstrap/cache/kinetix-discovery.php` by `php artisan kinetix:optimize`
 * (run by `php artisan optimize` on Laravel versions with provider optimize
 * hooks) and removed by `kinetix:optimize-clear`.
 *
 * With no manifest every root is scanned, as always: development, tests and
 * apps that never run `optimize` never see a stale list. A root that isn't in
 * the manifest is scanned too. Classes added to a root after `optimize` are
 * not seen until the next one, the same contract as `config:cache`; classes
 * deleted since are skipped rather than fatal.
 *
 * @internal
 */
final class DiscoveryManifest
{
    private const VERSION = 1;

    /**
     * The loaded manifest per path, for the life of the process (opcache
     * serves the include itself). Null: no usable manifest at that path.
     *
     * @var array<string, array<string, list<string>>|null>
     */
    private static array $loaded = [];

    public static function path(): string
    {
        return app()->bootstrapPath('cache/kinetix-discovery.php');
    }

    /**
     * The discovered classes of a root, or null when the root isn't in the
     * manifest (or there is none): scan it instead.
     *
     * @return list<class-string>|null
     */
    public static function classes(string $directory, string $namespace, string $parent): ?array
    {
        $entries = self::entries();

        if ($entries === null) {
            return null;
        }

        $key = self::key($directory, $namespace, $parent);

        if (! array_key_exists($key, $entries)) {
            return null;
        }

        $classes = [];

        foreach ($entries[$key] as $class) {
            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * Whether a root is in the manifest (no manifest: false).
     */
    public static function covers(string $directory, string $namespace, string $parent): bool
    {
        $entries = self::entries();

        return $entries !== null && array_key_exists(self::key($directory, $namespace, $parent), $entries);
    }

    /**
     * Whether a manifest exists.
     */
    public static function exists(): bool
    {
        return self::entries() !== null;
    }

    /**
     * @param array<string, list<class-string>> $entries keyed by {@see key()}
     */
    public static function write(array $entries): void
    {
        $path = self::path();

        File::ensureDirectoryExists(dirname($path));
        File::replace($path, '<?php'.PHP_EOL.PHP_EOL.'return '.var_export([
            'version' => self::VERSION,
            'entries' => $entries,
        ], true).';'.PHP_EOL);

        self::invalidateOpcache($path);
        self::flush();
    }

    public static function clear(): void
    {
        $path = self::path();

        if (is_file($path)) {
            File::delete($path);
            self::invalidateOpcache($path);
        }

        self::flush();
    }

    /**
     * Drop the per-process copy (tests, and after write/clear).
     */
    public static function flush(): void
    {
        self::$loaded = [];
    }

    /**
     * A root's manifest key: its directory (relative to the app's base path
     * when inside it, so the manifest survives a release directory swap), its
     * namespace and the class the discovered ones extend.
     */
    public static function key(string $directory, string $namespace, string $parent): string
    {
        $directory = rtrim(str_replace('\\', '/', $directory), '/');
        $base      = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        if (str_starts_with($directory.'/', $base)) {
            $directory = substr($directory, strlen($base));
        }

        return $directory.'|'.rtrim($namespace, '\\').'|'.ltrim($parent, '\\');
    }

    /**
     * @return array<string, list<string>>|null
     */
    private static function entries(): ?array
    {
        $path = self::path();

        if (! array_key_exists($path, self::$loaded)) {
            self::$loaded[$path] = self::load($path);
        }

        return self::$loaded[$path];
    }

    /**
     * @return array<string, list<string>>|null
     */
    private static function load(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $data = require $path;

        if (! is_array($data) || ($data['version'] ?? null) !== self::VERSION || ! is_array($data['entries'] ?? null)) {
            return null;
        }

        $entries = [];

        foreach ($data['entries'] as $key => $classes) {
            if (is_string($key) && is_array($classes)) {
                $entries[$key] = array_values(array_filter($classes, 'is_string'));
            }
        }

        return $entries;
    }

    private static function invalidateOpcache(string $path): void
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }
}
