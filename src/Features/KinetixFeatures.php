<?php

declare(strict_types=1);

namespace Happones\Kinetix\Features;

use Closure;

/**
 * Static entry point for feature flags. Define flags in a service provider:
 *
 *     KinetixFeatures::define('beta-search', fn ($user) => $user->canUseFeature('search.beta'));
 *     KinetixFeatures::define('new-dashboard', true);
 *
 * then gate with `KinetixFeatures::active('beta-search')`, the `kinetix.feature`
 * middleware, or `<KinetixFeature flag="beta-search">` on the frontend.
 */
class KinetixFeatures
{
    public static function manager(): FeatureManager
    {
        return app(FeatureManager::class);
    }

    public static function define(string $name, Closure|bool $resolver): void
    {
        static::manager()->define($name, $resolver);
    }

    public static function active(string $name, mixed $scope = null): bool
    {
        return static::manager()->active($name, $scope);
    }

    public static function inactive(string $name, mixed $scope = null): bool
    {
        return static::manager()->inactive($name, $scope);
    }

    /**
     * @return array<string, bool>
     */
    public static function all(mixed $scope = null): array
    {
        return static::manager()->all($scope);
    }
}
