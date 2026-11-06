<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support\Concerns;

use Happones\Kinetix\Support\DiscoveryManifest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * Shared directory-scanning helper for registries that auto-discover classes
 * from a conventional app folder (`discoverResources(in:, for:)`), generalized
 * over the required parent class/interface. A registry using it lists its
 * roots in `discoveryRoots()`, which `kinetix:optimize` caches.
 */
trait DiscoversClasses
{
    /**
     * The concrete classes under `$namespace` in `$directory` that are a
     * subclass (or interface implementation) of `$parentClass`: from the
     * discovery manifest when `kinetix:optimize` cached this root
     * ({@see DiscoveryManifest}), else from a directory scan.
     *
     * @param  class-string             $parentClass
     * @return array<int, class-string>
     */
    protected function scanForSubclasses(string $directory, string $namespace, string $parentClass): array
    {
        return DiscoveryManifest::classes($directory, $namespace, $parentClass)
            ?? $this->scanDirectoryForSubclasses($directory, $namespace, $parentClass);
    }

    /**
     * Scan a directory for concrete classes under `$namespace` that are a
     * subclass (or interface implementation) of `$parentClass`. Missing
     * directories, non-PHP files, non-matching classes and abstracts are
     * skipped silently. Never reads the manifest: `kinetix:optimize` writes
     * it from this.
     *
     * @internal
     *
     * @param  class-string             $parentClass
     * @return array<int, class-string>
     */
    public function scanDirectoryForSubclasses(string $directory, string $namespace, string $parentClass): array
    {
        if (! File::isDirectory($directory)) {
            return [];
        }

        $namespace = rtrim($namespace, '\\');
        $classes   = [];

        foreach (File::allFiles($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = (string) Str::of($file->getRelativePathname())
                ->replace(['/', '\\', '.php'], ['\\', '\\', '']);

            $class = $namespace.'\\'.$relative;

            if (! class_exists($class) || ! is_subclass_of($class, $parentClass)) {
                continue;
            }

            if ((new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }
}
