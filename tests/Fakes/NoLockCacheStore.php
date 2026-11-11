<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Fakes;

use Illuminate\Contracts\Cache\Store;

/** A cache store that cannot lock. */
class NoLockCacheStore implements Store
{
    public function get($key): mixed
    {
        return null;
    }

    public function many(array $keys): array
    {
        return array_fill_keys($keys, null);
    }

    public function put($key, $value, $seconds): bool
    {
        return true;
    }

    public function putMany(array $values, $seconds): bool
    {
        return true;
    }

    public function increment($key, $value = 1): int|bool
    {
        return false;
    }

    public function decrement($key, $value = 1): int|bool
    {
        return false;
    }

    public function forever($key, $value): bool
    {
        return true;
    }

    public function forget($key): bool
    {
        return true;
    }

    public function flush(): bool
    {
        return true;
    }

    public function touch($key, $seconds): bool
    {
        return true;
    }

    public function getPrefix(): string
    {
        return '';
    }
}
