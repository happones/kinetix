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
        $this->discovered      = null; // a new path invalidates the memoized scan
    }

    /**
     * Discovered class names, memoized for the life of the singleton.
     *
     * @var array<int, class-string<SpotlightSource>>|null
     */
    protected ?array $discovered = null;

    /**
     * @return array<int, SpotlightSource>
     */
    public function sources(): array
    {
        $sources = $this->sources;

        foreach ($this->discoveredClasses() as $class) {
            // Resolved PER CALL, on purpose. A source reads request state when
            // it is built — the active locale for its group heading, the
            // current tenant for its query — so caching the *instances* would
            // freeze every source into whichever request (or queue worker) first
            // touched the registry. Only the directory scan is memoized.
            $sources[] = app($class);
        }

        return $sources;
    }

    /**
     * The class-name scan, run once instead of once per search.
     *
     * `scanForSubclasses()` walks the directory and reflects every file in it;
     * at one call per keystroke that is a filesystem walk and N reflections on
     * the hot path, for a list that cannot change within a request.
     *
     * @return array<int, class-string<SpotlightSource>>
     */
    protected function discoveredClasses(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        $classes = [];

        foreach ($this->discoverPaths as [$directory, $namespace]) {
            foreach ($this->scanForSubclasses($directory, $namespace, SpotlightSource::class) as $class) {
                $classes[] = $class;
            }
        }

        return $this->discovered = $classes;
    }
}
