<?php

declare(strict_types=1);

namespace Happones\Kinetix\Spotlight;

/**
 * Accumulates the registered spotlight sources (declared by the host via
 * `KinetixSpotlight::register([...])`). Bound as a singleton.
 */
class SpotlightRegistry
{
    /**
     * @var array<int, SpotlightSource>
     */
    protected array $sources = [];

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
     * @return array<int, SpotlightSource>
     */
    public function sources(): array
    {
        return $this->sources;
    }
}
