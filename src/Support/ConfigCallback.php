<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use Closure;

/**
 * Resolves a config value that may be a callback into something callable.
 *
 * A `Closure` in a config file makes the config **uncacheable** — `php artisan
 * config:cache` aborts with "Your configuration files could not be serialized
 * because the value at … is non-serializable", which means a closure-based
 * example is not deployable. So every Kinetix option documented as "a callback"
 * also accepts two serializable forms, both resolved through the container here:
 *
 *  - a **callable array** — `[SyncProvisionedMember::class, 'attach']` (the
 *    method may be static or an instance method, which is then resolved out of
 *    the container);
 *  - the **class-string of an invokable class** — `SyncProvisionedMember::class`.
 *
 * A plain array that isn't a `[class-string, method]` pair is left alone, so an
 * option that legitimately takes a list of strings (e.g.
 * `membership.assignable_roles`) keeps working.
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

        return static::resolveCallableArray($value);
    }

    /**
     * `[SyncProvisionedMember::class, 'attach']` / `[$object, 'attach']` — the
     * serializable callback form. A class-string pair whose method isn't static
     * is bound to a container-resolved instance.
     */
    protected static function resolveCallableArray(mixed $value): ?callable
    {
        if (! is_array($value) || count($value) !== 2) {
            return null;
        }

        [$target, $method] = [$value[0] ?? null, $value[1] ?? null];

        if (! is_string($method)) {
            return null;
        }

        if (is_object($target)) {
            return method_exists($target, $method) ? [$target, $method] : null;
        }

        // Not a class name → a plain list of strings (role names, …), not a callback.
        if (! is_string($target) || ! class_exists($target) || ! method_exists($target, $method)) {
            return null;
        }

        return is_callable([$target, $method])
            ? [$target, $method]              // static
            : [app($target), $method];        // instance method, container-resolved
    }
}
