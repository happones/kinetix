<?php

declare(strict_types=1);

namespace Happones\Kinetix\Permissions;

/**
 * The single source of truth for Kinetix permissions. Features can be declared
 * explicitly (`feature('billing')->ability('manage')`) or auto-derived from a
 * Kinetix Resource (`resource(PostResource::class)`), giving the hybrid model:
 * zero boilerplate for resources, full control for everything else.
 *
 * Bound as a singleton so app code accumulates definitions, then the
 * `kinetix:permissions:sync` command and the frontend share the same canonical
 * `{feature}.{ability}` keys.
 */
class PermissionRegistry
{
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
        if ($this->resources === []) {
            return;
        }

        $pending         = $this->resources;
        $this->resources = [];

        foreach ($pending as $resourceClass) {
            $resourceClass::registerPermissions($this);
        }
    }
}
