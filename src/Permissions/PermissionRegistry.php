<?php

declare(strict_types=1);

namespace Happones\Kinetix\Permissions;

use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Support\Concerns\DiscoversClasses;

/**
 * The single source of truth for Kinetix permissions. Features can be declared
 * explicitly (`feature('billing')->ability('manage')`), auto-derived from a
 * Kinetix Resource (`resource(PostResource::class)`), or auto-discovered from a
 * conventional resources directory (`discoverResources(in:, for:)`), giving the
 * hybrid model: zero boilerplate for resources, full control for everything else.
 *
 * Bound as a singleton so app code accumulates definitions, then the
 * `kinetix:permissions:sync` command and the frontend share the same canonical
 * `{feature}.{ability}` keys.
 */
class PermissionRegistry
{
    use DiscoversClasses;

    /**
     * @var array<string, Feature>
     */
    protected array $features = [];

    /**
     * Resource classes whose permissions are derived lazily.
     *
     * @var array<int, class-string>
     */
    protected array $resources = [];

    /**
     * Directories (+ base namespace) scanned for `Resource` subclasses.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    protected array $discoverPaths = [];

    /**
     * Resource classes already resolved, so repeat `features()` calls don't
     * re-run their `registerPermissions()` hook.
     *
     * @var array<class-string, true>
     */
    protected array $resolved = [];

    /**
     * Discover paths already scanned (`"dir|namespace"` keys), so repeat
     * `features()` calls don't re-hit the filesystem. A newly added path via
     * `discoverResources()` is scanned once on the next resolve.
     *
     * @var array<string, true>
     */
    protected array $scannedPaths = [];

    /**
     * Declare (or fetch) a feature by name.
     */
    public function feature(string $name): Feature
    {
        return $this->features[$name] ??= new Feature($name);
    }

    /**
     * Register a Kinetix Resource whose permissions are auto-derived from its
     * `registerPermissions()` hook (default: CRUD).
     *
     * @param class-string $resourceClass
     */
    public function resource(string $resourceClass): static
    {
        if (! in_array($resourceClass, $this->resources, true)) {
            $this->resources[] = $resourceClass;
        }

        return $this;
    }

    /**
     * Auto-discover `Resource` subclasses in a directory and derive their
     * permissions, additive to any manual `resource()` calls. Resources that
     * don't override `permissionFeature()` opt out silently, so scanning never
     * over-grants. Call multiple times to add more roots.
     */
    public function discoverResources(string $directory, string $namespace): static
    {
        $this->discoverPaths[] = [$directory, rtrim($namespace, '\\')];

        return $this;
    }

    /**
     * All features, with resource-derived ones resolved.
     *
     * @return array<string, Feature>
     */
    public function features(): array
    {
        $this->resolveResources();

        return $this->features;
    }

    /**
     * Every canonical permission key across all features.
     *
     * @return array<int, string>
     */
    public function allPermissions(): array
    {
        $keys = [];

        foreach ($this->features() as $feature) {
            $keys = [...$keys, ...$feature->permissionKeys()];
        }

        return array_values(array_unique($keys));
    }

    protected function resolveResources(): void
    {
        $pending         = $this->resources;
        $this->resources = [];

        foreach ($this->discoverPaths as [$directory, $namespace]) {
            $key = $directory.'|'.$namespace;

            if (isset($this->scannedPaths[$key])) {
                continue;
            }

            $this->scannedPaths[$key] = true;
            $pending                  = [...$pending, ...$this->scanForSubclasses($directory, $namespace, Resource::class)];
        }

        foreach (array_unique($pending) as $resourceClass) {
            if (isset($this->resolved[$resourceClass])) {
                continue;
            }

            $this->resolved[$resourceClass] = true;
            $resourceClass::registerPermissions($this);
        }
    }
}
