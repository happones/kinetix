<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Serializes a check-then-write across requests, where the cache store can
 * lock; without one (a store with no locks) the write runs unguarded and the
 * unique key and its violation handling cover it.
 *
 * @internal
 */
final class RoutingLock
{
    /**
     * @template TReturn
     *
     * @param  callable(): TReturn $write
     * @return TReturn
     */
    public static function run(string $key, callable $write): mixed
    {
        $store = Cache::getStore();

        if (! $store instanceof LockProvider) {
            return $write();
        }

        try {
            return $store->lock($key, 10)->block(5, $write);
        } catch (LockTimeoutException) {
            abort(409);
        }
    }

    /**
     * Whether a key read from a request can be a key of a model: an integer
     * key only holds digits. A string or UUID key accepts anything. Without
     * this a database that types the comparison (Postgres) fails on it.
     */
    public static function plausibleKey(string $key, string $keyType = 'int'): bool
    {
        return $keyType !== 'int' || ($key !== '' && ctype_digit($key) && strlen($key) <= 19);
    }
}
