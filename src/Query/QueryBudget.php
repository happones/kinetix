<?php

declare(strict_types=1);

namespace Happones\Kinetix\Query;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use PDO;
use Throwable;

/**
 * Runs a lookup query under a statement time budget, so one pathological
 * search cannot hold a database worker for minutes.
 *
 * The limit lives in the session for the duration of the callback only and
 * is always put back (`finally`), so a pooled or persistent connection never
 * carries it into later work:
 *
 * - MySQL: `max_execution_time` (milliseconds, SELECT only; error 3024).
 * - MariaDB: `max_statement_time` (seconds; error 1969).
 * - PostgreSQL: `SET LOCAL statement_timeout` inside its own transaction
 *   (SQLSTATE 57014). Inside a caller's transaction the budget is skipped:
 *   `SET LOCAL` would outlive the lookup and apply to the caller's later
 *   statements.
 * - SQLite and SQL Server have no equivalent: the callback just runs.
 *
 * A cancelled query surfaces as {@see QueryBudgetExceeded}; any other
 * failure passes through unchanged.
 */
final class QueryBudget
{
    /** The configured budget in milliseconds, or null when it is off. */
    public static function configured(): ?int
    {
        $value = config('kinetix.search.timeout');

        if (! is_numeric($value) || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @template TReturn
     *
     * @param callable(): TReturn $fn
     *
     * @throws QueryBudgetExceeded
     *
     * @return TReturn
     */
    public static function run(ConnectionInterface $connection, ?int $ms, callable $fn): mixed
    {
        if ($ms === null || $ms < 1) {
            return $fn();
        }

        try {
            return match (self::driver($connection)) {
                'mysql'   => self::session($connection, 'max_execution_time', (string) $ms, $fn),
                'mariadb' => self::session($connection, 'max_statement_time', self::seconds($ms), $fn),
                'pgsql'   => self::postgres($connection, $ms, $fn),
                default   => $fn(),
            };
        } catch (QueryException $e) {
            if (self::isTimeout($e)) {
                throw QueryBudgetExceeded::from($e);
            }

            throw $e;
        }
    }

    /** Whether the database cancelled the statement for running too long. */
    public static function isTimeout(QueryException $e): bool
    {
        $info = $e->errorInfo;

        if (is_array($info) && ($info[0] ?? null) === '57014') {
            return true;
        }

        $code = is_array($info) ? (int) ($info[1] ?? 0) : 0;

        if ($code === 3024 || $code === 1969) {
            return true;
        }

        // A filesort cancelled by the limit reports ER_FILSORT_ABORT.
        return $code === 1028 && str_contains($e->getMessage(), 'maximum statement execution time');
    }

    /**
     * MySQL and MariaDB keep the limit per session, so it is set on the PDO
     * the lookup's `get()` reads from (`getReadPdo()`): with a read/write
     * split that is the replica, and the write side is never touched. The raw
     * PDO is used on purpose: `statement()` would mark the connection as
     * having written and make later reads sticky.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn $fn
     * @return TReturn
     */
    protected static function session(ConnectionInterface $connection, string $variable, string $value, callable $fn): mixed
    {
        $pdo = method_exists($connection, 'getReadPdo') ? $connection->getReadPdo() : null;

        if (! $pdo instanceof PDO) {
            return $fn();
        }

        try {
            $previous = self::currentValue($pdo, $variable);
            $pdo->exec("SET SESSION {$variable} = {$value}");
        } catch (Throwable $e) {
            Log::debug('Kinetix lookup ran without a query budget: '.$e->getMessage());

            return $fn();
        }

        try {
            return $fn();
        } finally {
            try {
                $pdo->exec("SET SESSION {$variable} = {$previous}");
            } catch (Throwable) {
                // The connection is gone; the next one starts clean.
            }
        }
    }

    /**
     * PostgreSQL only has `SET LOCAL`, which needs a transaction, and a
     * transaction runs on the write connection: with a read/write split the
     * budgeted lookup goes to the primary.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn $fn
     * @return TReturn
     */
    protected static function postgres(ConnectionInterface $connection, int $ms, callable $fn): mixed
    {
        if ($connection->transactionLevel() > 0) {
            return $fn();
        }

        try {
            return $connection->transaction(static function () use ($connection, $ms, $fn): mixed {
                try {
                    $connection->statement("SET LOCAL statement_timeout = {$ms}");
                } catch (Throwable $e) {
                    throw new QueryBudgetUnavailable($e->getMessage(), 0, $e);
                }

                return $fn();
            });
        } catch (QueryBudgetUnavailable $e) {
            Log::debug('Kinetix lookup ran without a query budget: '.$e->getMessage());

            return $fn();
        }
    }

    /** The session's value now (put back afterwards); an unreadable one fails the budget. */
    protected static function currentValue(PDO $pdo, string $variable): string
    {
        $statement = $pdo->query("SELECT @@SESSION.{$variable}");
        $value     = $statement === false ? null : $statement->fetchColumn();

        return is_numeric($value) ? (string) $value : 'DEFAULT';
    }

    /** `max_statement_time` takes seconds, fractions allowed. */
    protected static function seconds(int $ms): string
    {
        return rtrim(rtrim(number_format($ms / 1000, 3, '.', ''), '0'), '.');
    }

    protected static function driver(ConnectionInterface $connection): string
    {
        $name = method_exists($connection, 'getDriverName') ? (string) $connection->getDriverName() : '';

        if ($name === 'mysql' && method_exists($connection, 'isMaria') && $connection->isMaria()) {
            return 'mariadb';
        }

        return $name;
    }
}
