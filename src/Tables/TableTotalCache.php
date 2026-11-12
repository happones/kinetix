<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables;

use Happones\Kinetix\Impersonation\KinetixImpersonation;
use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use JsonException;
use Throwable;

/**
 * Remembers a table's row total for a short time, so paging and re-sorting a
 * large list does not run the same `COUNT(*)` on every request.
 *
 * The key is the identity of the question, never of the page: the connection,
 * the team, the user (and who is impersonating them), and the count statement
 * with its bindings. Two teams, two users or two filters can therefore never
 * share an entry. With teams on and no team resolved there is nothing safe to
 * key on, so nothing is read or written (fail closed: the count runs fresh).
 *
 * @internal
 */
final class TableTotalCache
{
    private const PREFIX = 'kinetix:table-total:v1:';

    /**
     * The cache key for this query's count, or null when it must not be
     * cached (no resolvable team with teams on, or unencodable bindings).
     *
     * @param Builder<*> $query
     */
    public static function keyFor(Builder $query): ?string
    {
        $team = '-';

        if (KinetixTeams::enabledFor('tables')) {
            $key = KinetixTeams::currentTeamKey();

            if ($key === null) {
                return null;
            }

            $team = (string) $key;
        }

        $count      = $query->toBase()->cloneWithout(['orders', 'limit', 'offset'])->cloneWithoutBindings(['order']);
        $connection = $count->getConnection();
        $user       = auth()->user();

        if (! $connection instanceof Connection) {
            return null;
        }

        try {
            $identity = json_encode([
                $connection->getName(),
                $connection->getDatabaseName(),
                $team,
                $user === null ? 'guest' : $user::class,
                $user === null ? null : $user->getAuthIdentifier(),
                KinetixImpersonation::impersonatorId(),
                $count->toSql(),
                $connection->prepareBindings($count->getBindings()),
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return self::PREFIX.hash('sha256', $identity);
    }

    public static function get(string $key): ?int
    {
        try {
            $value = self::store()->get($key);
        } catch (Throwable) {
            return null;
        }

        return is_int($value) ? $value : null;
    }

    public static function put(string $key, int $total, int $ttlSeconds): void
    {
        try {
            self::store()->put($key, $total, $ttlSeconds);
        } catch (Throwable) {
            // A cache that cannot store only costs the next request a count.
        }
    }

    public static function forget(string $key): void
    {
        try {
            self::store()->forget($key);
        } catch (Throwable) {
            // See put().
        }
    }

    /** The configured seconds a total is kept. */
    public static function defaultTtl(): int
    {
        $ttl = config('kinetix.tables.cached_total.ttl', 60);

        return is_numeric($ttl) && (int) $ttl >= 1 ? (int) $ttl : 60;
    }

    /** The configured row count below which a total is always counted fresh. */
    public static function defaultMinimum(): int
    {
        $minimum = config('kinetix.tables.cached_total.minimum', 10000);

        return is_numeric($minimum) && (int) $minimum >= 0 ? (int) $minimum : 10000;
    }

    private static function store(): Repository
    {
        $name = config('kinetix.tables.cached_total.store');

        return Cache::store(is_string($name) && $name !== '' ? $name : null);
    }
}
