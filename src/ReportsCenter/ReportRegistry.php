<?php

declare(strict_types=1);

namespace Happones\Kinetix\ReportsCenter;

use Happones\Kinetix\Support\Concerns\DiscoversClasses;
use RuntimeException;

/**
 * Registry of known `Report` types. Unlike every other Kinetix registry
 * (which is filled exclusively by explicit `::register()` calls in a host
 * service provider), this one ALSO auto-discovers `Report` subclasses from a
 * configurable directory — a deliberate exception, since the launcher UI
 * needs to enumerate report types, and manual-only registration would add
 * friction to something meant to feel like "drop a class in a folder, like a
 * Job." Manual `register()` stays available (additive) for classes living
 * outside the discovered directory.
 */
class ReportRegistry
{
    use DiscoversClasses;

    /**
     * @var array<class-string<Report>, true>
     */
    protected array $manual = [];

    /**
     * @var array<int, array{0: string, 1: string}>
     */
    protected array $discoverPaths = [];

    /**
     * @param class-string<Report> $reportClass
     */
    public function register(string $reportClass): void
    {
        $this->assertValid($reportClass);

        $this->manual[$reportClass] = true;
    }

    /**
     * Add another directory (+ its base namespace) to scan for `Report`
     * subclasses. Additive — call multiple times to add more roots.
     */
    public function discover(string $directory, string $namespace): void
    {
        $this->discoverPaths[] = [$directory, rtrim($namespace, '\\')];
    }

    /**
     * The directories (+ base namespace) scanned for `Report` subclasses,
     * for `kinetix:optimize`.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function discoveryRoots(): array
    {
        return array_values($this->discoverPaths);
    }

    /**
     * @return array<int, class-string<Report>>
     */
    public function all(): array
    {
        $found = $this->manual;

        foreach ($this->discoverPaths as [$directory, $namespace]) {
            foreach ($this->scanForSubclasses($directory, $namespace, Report::class) as $class) {
                $found[$class] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * @return class-string<Report>|null
     */
    public function get(string $reportClass): ?string
    {
        return in_array($reportClass, $this->all(), true) ? $reportClass : null;
    }

    protected function assertValid(string $reportClass): void
    {
        if (! class_exists($reportClass) || ! is_subclass_of($reportClass, Report::class)) {
            throw new RuntimeException("[{$reportClass}] must be a subclass of ".Report::class);
        }
    }
}
