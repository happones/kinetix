<?php

declare(strict_types=1);

namespace Happones\Kinetix\Permissions;

/**
 * Static entry point for declaring Kinetix permissions, typically from a service
 * provider:
 *
 *     KinetixPermissions::resource(PostResource::class);
 *     KinetixPermissions::discoverResources(in: app_path('Kinetix/Resources'), for: 'App\Kinetix\Resources');
 *     KinetixPermissions::feature('billing')->label('Billing')->ability('manage');
 */
class KinetixPermissions
{
    public static function registry(): PermissionRegistry
    {
        return app(PermissionRegistry::class);
    }

    public static function feature(string $name): Feature
    {
        return static::registry()->feature($name);
    }

    /**
     * @param class-string $resourceClass
     */
    public static function resource(string $resourceClass): PermissionRegistry
    {
        return static::registry()->resource($resourceClass);
    }

    /**
     * Auto-discover `Resource` subclasses in a directory and derive their
     * permissions (additive to manual `resource()` calls).
     */
    public static function discoverResources(string $in, string $for): PermissionRegistry
    {
        return static::registry()->discoverResources($in, $for);
    }

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return static::registry()->allPermissions();
    }
}
