<?php

declare(strict_types=1);

namespace Happones\Kinetix\Spotlight;

use Happones\Kinetix\Support\Concerns\DiscoversClasses;

/**
 * Accumulates the registered spotlight sources (declared by the host via
 * `KinetixSpotlight::register([...])`) and optionally auto-discovers
 * `SpotlightSource` implementations from a conventional directory. Bound as a
 * singleton.
 */
class SpotlightRegistry
{
    use DiscoversClasses;

    /**
     * @var array<int, SpotlightSource>
     */
    protected array $sources = [];

    /**
     * Directories (+ base namespace) scanned for `SpotlightSource` classes.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    protected array $discoverPaths = [];

    /**
     * @param array<int, SpotlightSource> $sources
     */
    public function register(array $sources): void
    {
        foreach ($sources as $source) {
            $this->sources[] = $source;
        }
    }

    /**
     * Add a directory (+ base namespace) to scan for `SpotlightSource`
     * implementations. Discovered classes are resolved from the container, so
     * they may declare constructor dependencies. Additive.
     */
    public function discover(string $directory, string $namespace): void
    {
        $this->discoverPaths[] = [$directory, rtrim($namespace, '\\')];
    }

    /**
     * @return array<int, SpotlightSource>
     */
    public function sources(): array
    {
        $sources = $this->sources;

        foreach ($this->discoverPaths as [$directory, $namespace]) {
            foreach ($this->scanForSubclasses($directory, $namespace, SpotlightSource::class) as $class) {
                $sources[] = app($class);
            }
        }

        return $sources;
    }
}
