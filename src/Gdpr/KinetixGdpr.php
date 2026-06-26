<?php

declare(strict_types=1);

namespace Happones\Kinetix\Gdpr;

use Closure;

/**
 * Static entry point for the GDPR module. Declare the data sections a user can
 * download, and (optionally) a custom deletion handler, in a service provider:
 *
 *     KinetixGdpr::export('profile', fn ($user) => $user->only(['name', 'email']));
 *     KinetixGdpr::export('orders', fn ($user) => $user->orders);
 *     KinetixGdpr::deleteUsing(fn ($user) => $user->forceDelete());
 */
class KinetixGdpr
{
    public static function registry(): GdprRegistry
    {
        return app(GdprRegistry::class);
    }

    /**
     * @param Closure(mixed): mixed $resolver
     */
    public static function export(string $name, Closure $resolver): void
    {
        static::registry()->export($name, $resolver);
    }

    /**
     * @param Closure(mixed): void $callback
     */
    public static function deleteUsing(Closure $callback): void
    {
        static::registry()->deleteUsing($callback);
    }
}
