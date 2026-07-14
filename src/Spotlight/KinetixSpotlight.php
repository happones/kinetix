<?php

declare(strict_types=1);

namespace Happones\Kinetix\Spotlight;

/**
 * Static entry point for the spotlight command palette. Register sources in a
 * service provider:
 *
 *     KinetixSpotlight::register([
 *         SpotlightResource::make(Post::class)->titleAttribute('title')
 *             ->searchColumns(['title'])->url(fn ($p) => route('posts.edit', $p))->authorize('posts.viewAny'),
 *         SpotlightLink::make('Billing')->url('/billing')->icon('credit-card'),
 *     ]);
 */
class KinetixSpotlight
{
    public static function registry(): SpotlightRegistry
    {
        return app(SpotlightRegistry::class);
    }

    /**
     * @param array<int, SpotlightSource> $sources
     */
    public static function register(array $sources): void
    {
        static::registry()->register($sources);
    }

    /**
     * Auto-discover `SpotlightSource` implementations in a directory (additive
     * to manual `register()` calls). Discovered classes are resolved from the
     * container.
     */
    public static function discover(string $in, string $for): void
    {
        static::registry()->discover($in, $for);
    }
}
