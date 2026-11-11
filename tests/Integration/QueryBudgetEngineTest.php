<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Integration;

use Happones\Kinetix\Query\QueryBudget;
use Happones\Kinetix\Query\QueryBudgetExceeded;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Runs the statement budget against a real engine. Skipped unless
 * KINETIX_DB_INTEGRATION is mysql, mariadb or pgsql, with DB_HOST, DB_PORT,
 * DB_DATABASE, DB_USERNAME and DB_PASSWORD pointing at a disposable database:
 *
 *   KINETIX_DB_INTEGRATION=mysql DB_HOST=... DB_PORT=3306 DB_DATABASE=kinetix \
 *   DB_USERNAME=root DB_PASSWORD=secret \
 *   vendor/bin/testbench package:test tests/Integration/QueryBudgetEngineTest.php
 */
class QueryBudgetEngineTest extends TestCase
{
    private string $engine = '';

    protected function setUp(): void
    {
        parent::setUp();

        $engine = (string) getenv('KINETIX_DB_INTEGRATION');

        if (! in_array($engine, ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->markTestSkipped('Set KINETIX_DB_INTEGRATION=mysql|mariadb|pgsql to run the engine tests.');
        }

        $this->engine = $engine;

        config(['database.connections.engine' => [
            'driver'   => $engine === 'pgsql' ? 'pgsql' : ($engine === 'mariadb' ? 'mariadb' : 'mysql'),
            'host'     => (string) getenv('DB_HOST'),
            'port'     => (string) (getenv('DB_PORT') ?: ($engine === 'pgsql' ? '5432' : '3306')),
            'database' => (string) getenv('DB_DATABASE'),
            'username' => (string) getenv('DB_USERNAME'),
            'password' => (string) getenv('DB_PASSWORD'),
            'charset'  => $engine === 'pgsql' ? 'utf8' : 'utf8mb4',
        ]]);
    }

    private function connection(): Connection
    {
        return DB::connection('engine');
    }

    /** The session value the budget touches, as the engine reports it. */
    private function sessionValue(): string
    {
        $pdo = $this->connection()->getReadPdo();

        $query = $this->engine === 'pgsql'
            ? 'SHOW statement_timeout'
            : 'SELECT @@SESSION.'.($this->engine === 'mariadb' ? 'max_statement_time' : 'max_execution_time');

        $statement = $pdo->query($query);
        $this->assertNotFalse($statement);

        return (string) $statement->fetchColumn();
    }

    /** A statement that runs for many seconds without a sleep call (MySQL's SLEEP() is interrupted without an error). */
    private function slowQuery(bool $sorted): string
    {
        if ($this->engine === 'pgsql') {
            return $sorted
                ? 'SELECT x FROM generate_series(1, 300000000) x ORDER BY random() LIMIT 1'
                : 'SELECT pg_sleep(2)';
        }

        $cross = 'FROM information_schema.columns a, information_schema.columns b, information_schema.columns c';

        return $sorted ? "SELECT a.column_name {$cross} ORDER BY RAND() LIMIT 1" : "SELECT COUNT(*) {$cross}";
    }

    public function test_a_slow_query_is_cancelled_and_the_session_is_restored(): void
    {
        $before  = $this->sessionValue();
        $started = microtime(true);

        try {
            QueryBudget::run($this->connection(), 200, fn () => $this->connection()->select($this->slowQuery(false)));
            $this->fail('The query was not cancelled.');
        } catch (QueryBudgetExceeded) {
            $this->assertLessThan(1.8, microtime(true) - $started, 'The query ran past its budget.');
        }

        $this->assertSame($before, $this->sessionValue());
    }

    public function test_a_slow_sort_is_cancelled_too(): void
    {
        $before = $this->sessionValue();

        $this->expectException(QueryBudgetExceeded::class);

        try {
            QueryBudget::run($this->connection(), 200, fn () => $this->connection()->select($this->slowQuery(true)));
        } finally {
            $this->assertSame($before, $this->sessionValue());
        }
    }

    public function test_a_fast_query_under_the_budget_returns_its_rows(): void
    {
        $rows = QueryBudget::run($this->connection(), 5000, fn () => $this->connection()->select('SELECT 1 AS one'));

        $this->assertSame(1, (int) $rows[0]->one);
    }
}
