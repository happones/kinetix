<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use Closure;

/**
 * Resolves a config value that may be a callback into something callable.
 *
 * Config files that hold a `Closure` cannot be cached (`php artisan
 * config:cache` aborts with "Your configuration files are not serializable"),
 * so every Kinetix option documented as "a callback" also accepts the
 * **class-string of an invokable class** — which serializes fine and is
 * resolved out of the container here. Arrays are never treated as callbacks,
 * so an `[$object, 'method']` pair must be wrapped in a closure.
 */
class ConfigCallback
{
    /**
     * @return callable|null Null when the value isn't a callback at all.
     */
    public static function resolve(mixed $value): ?callable
    {
        if ($value instanceof Closure) {
            return $value;
        }

        // An invokable class-string: `App\Kinetix\AssignableRoles::class`.
        // Kept config:cache-safe (a plain string) unlike a closure.
        if (is_string($value) && class_exists($value) && method_exists($value, '__invoke')) {
            return app($value);
        }

        if (is_object($value) && method_exists($value, '__invoke')) {
            return $value;
        }

        return null;
    }
}
