<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * Shared directory-scanning helper for registries that auto-discover classes
 * from a conventional app folder (the Filament `discoverResources(in:, for:)`
 * ergonomic). Mirrors the original implementation in the Reports Center's
 * `ReportRegistry::scan()`, generalized over the required parent class/interface.
 */
trait DiscoversClasses
{
    /**
     * Scan a directory for concrete classes under `$namespace` that are a
     * subclass (or interface implementation) of `$parentClass`. Missing
     * directories, non-PHP files, non-matching classes and abstracts are
     * skipped silently.
     *
     * @param  class-string             $parentClass
     * @return array<int, class-string>
     */
    protected function scanForSubclasses(string $directory, string $namespace, string $parentClass): array
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
