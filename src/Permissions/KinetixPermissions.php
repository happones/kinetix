<?php

declare(strict_types=1);

namespace Happones\Kinetix\Permissions;

/**
 * Static entry point for declaring Kinetix permissions, typically from a service
 * provider:
 *
 *     KinetixPermissions::resource(PostResource::class);
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
     * @return array<int, string>
     */
    public static function all(): array
    {
        return static::registry()->allPermissions();
    }
}
