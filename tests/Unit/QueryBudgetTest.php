<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Query\QueryBudget;
use Happones\Kinetix\Query\QueryBudgetExceeded;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Mockery;
use Mockery\MockInterface;
use PDO;
use PDOException;
use PDOStatement;

class QueryBudgetTest extends TestCase
{
    private function timeout(int|string $driverCode, string $state = 'HY000'): QueryException
    {
        $pdo            = new PDOException('cancelled');
        $pdo->errorInfo = [$state, $driverCode, 'cancelled'];

        return new QueryException('mysql', 'select 1', [], $pdo);
    }

    /** @return Connection&MockInterface */
    private function connection(string $driver): Connection
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn($driver);

        return $connection;
    }

    public function test_configured_reads_the_timeout_or_is_off(): void
    {
        $this->assertNull(QueryBudget::configured());

        config()->set('kinetix.search.timeout', '750');
        $this->assertSame(750, QueryBudget::configured());

        config()->set('kinetix.search.timeout', 0);
        $this->assertNull(QueryBudget::configured());
    }

    public function test_without_a_budget_the_callback_just_runs(): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldNotReceive('statement');

        $this->assertSame('done', QueryBudget::run($connection, null, fn () => 'done'));
        $this->assertSame('done', QueryBudget::run($connection, 0, fn () => 'done'));
    }

    /**
     * A connection whose read and write PDOs are different mocks; the write
     * side must never be touched.
     *
     * @return array{0: Connection&MockInterface, 1: PDO&MockInterface, 2: PDO&MockInterface}
     */
    private function splitConnection(string $driver, bool $maria = false): array
    {
        $read  = Mockery::mock(PDO::class);
        $write = Mockery::mock(PDO::class);
        $write->shouldNotReceive('exec');
        $write->shouldNotReceive('query');

        $connection = Mockery::mock($maria ? MySqlConnection::class : Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn($driver);
        $connection->shouldReceive('getReadPdo')->andReturn($read);
        $connection->shouldReceive('getPdo')->andReturn($write);
        $connection->shouldNotReceive('statement');
        $connection->shouldNotReceive('selectOne');

        if ($maria) {
            $connection->shouldReceive('isMaria')->andReturn(true);
        }

        return [$connection, $read, $write];
    }

    private function previousValue(PDO&MockInterface $pdo, string $variable, string|int $value): void
    {
        $statement = Mockery::mock(PDOStatement::class);
        $statement->shouldReceive('fetchColumn')->andReturn($value);
        $pdo->shouldReceive('query')->once()->with("SELECT @@SESSION.{$variable}")->andReturn($statement);
    }

    public function test_mysql_sets_the_limit_on_the_read_pdo_and_puts_the_previous_one_back(): void
    {
        [$connection, $read] = $this->splitConnection('mysql');
        $this->previousValue($read, 'max_execution_time', 0);
        $read->shouldReceive('exec')->once()->ordered()->with('SET SESSION max_execution_time = 500');
        $read->shouldReceive('exec')->once()->ordered()->with('SET SESSION max_execution_time = 0');

        $this->assertSame('rows', QueryBudget::run($connection, 500, fn () => 'rows'));
    }

    public function test_mysql_restores_the_session_even_when_the_callback_fails(): void
    {
        [$connection, $read] = $this->splitConnection('mysql');
        $this->previousValue($read, 'max_execution_time', '0');
        $read->shouldReceive('exec')->once()->ordered()->with('SET SESSION max_execution_time = 500');
        $read->shouldReceive('exec')->once()->ordered()->with('SET SESSION max_execution_time = 0');

        $this->expectException(\LogicException::class);

        QueryBudget::run($connection, 500, static fn () => throw new \LogicException('x'));
    }

    public function test_a_failing_restore_never_hides_the_result(): void
    {
        [$connection, $read] = $this->splitConnection('mysql');
        $this->previousValue($read, 'max_execution_time', 0);
        $read->shouldReceive('exec')->with('SET SESSION max_execution_time = 500')->once()->ordered();
        $read->shouldReceive('exec')->with('SET SESSION max_execution_time = 0')->once()->ordered()->andThrow(new \RuntimeException('gone'));

        $this->assertSame('rows', QueryBudget::run($connection, 500, fn () => 'rows'));
    }

    public function test_a_limit_that_cannot_be_set_runs_the_lookup_without_a_budget(): void
    {
        [$connection, $read] = $this->splitConnection('mysql');
        $this->previousValue($read, 'max_execution_time', 0);
        $read->shouldReceive('exec')->once()->with('SET SESSION max_execution_time = 500')->andThrow(new \RuntimeException('denied'));

        $this->assertSame('rows', QueryBudget::run($connection, 500, fn () => 'rows'));
    }

    public function test_mysql_timeout_becomes_a_budget_exception(): void
    {
        [$connection, $read] = $this->splitConnection('mysql');
        $this->previousValue($read, 'max_execution_time', 0);
        $read->shouldReceive('exec')->twice();

        $this->expectException(QueryBudgetExceeded::class);

        QueryBudget::run($connection, 500, fn () => throw $this->timeout(3024));
    }

    public function test_mariadb_uses_max_statement_time_in_seconds_on_the_read_pdo(): void
    {
        [$connection, $read] = $this->splitConnection('mysql', maria: true);
        $this->previousValue($read, 'max_statement_time', '0.000000');
        $read->shouldReceive('exec')->once()->ordered()->with('SET SESSION max_statement_time = 1.5');
        $read->shouldReceive('exec')->once()->ordered()->with('SET SESSION max_statement_time = 0.000000');

        $this->assertSame(1, QueryBudget::run($connection, 1500, fn () => 1));
    }

    public function test_a_filesort_abort_counts_as_a_timeout_only_for_the_statement_limit(): void
    {
        $pdo            = new PDOException('Sort aborted: Query execution was interrupted, maximum statement execution time exceeded');
        $pdo->errorInfo = ['HY000', 1028, 'Sort aborted: Query execution was interrupted, maximum statement execution time exceeded'];
        $this->assertTrue(QueryBudget::isTimeout(new QueryException('mysql', 'select 1', [], $pdo)));

        $other            = new PDOException('Sort aborted: disk full');
        $other->errorInfo = ['HY000', 1028, 'Sort aborted: disk full'];
        $this->assertFalse(QueryBudget::isTimeout(new QueryException('mysql', 'select 1', [], $other)));
    }

    public function test_mariadb_timeout_is_recognised(): void
    {
        $this->assertTrue(QueryBudget::isTimeout($this->timeout(1969)));
        $this->assertTrue(QueryBudget::isTimeout($this->timeout('3024')));
    }

    public function test_postgres_wraps_the_lookup_in_a_local_timeout(): void
    {
        $connection = $this->connection('pgsql');
        $connection->shouldReceive('transactionLevel')->andReturn(0);
        $connection->shouldReceive('transaction')->once()->andReturnUsing(fn (callable $callback) => $callback());
        $connection->shouldReceive('statement')->once()->with('SET LOCAL statement_timeout = 800');

        $this->assertSame('rows', QueryBudget::run($connection, 800, fn () => 'rows'));
    }

    public function test_postgres_that_cannot_set_the_limit_runs_without_a_budget(): void
    {
        $connection = $this->connection('pgsql');
        $connection->shouldReceive('transactionLevel')->andReturn(0);
        $connection->shouldReceive('transaction')->andReturnUsing(fn (callable $callback) => $callback());
        $connection->shouldReceive('statement')->once()->andThrow(new \RuntimeException('denied'));

        $this->assertSame('rows', QueryBudget::run($connection, 800, fn () => 'rows'));
    }

    public function test_postgres_skips_the_budget_inside_a_callers_transaction(): void
    {
        $connection = $this->connection('pgsql');
        $connection->shouldReceive('transactionLevel')->andReturn(1);
        $connection->shouldNotReceive('transaction');
        $connection->shouldNotReceive('statement');

        $this->assertSame('rows', QueryBudget::run($connection, 800, fn () => 'rows'));
    }

    public function test_postgres_timeout_is_recognised_by_sqlstate(): void
    {
        $connection = $this->connection('pgsql');
        $connection->shouldReceive('transactionLevel')->andReturn(0);
        $connection->shouldReceive('transaction')->andReturnUsing(fn (callable $callback) => $callback());
        $connection->shouldReceive('statement');

        $this->expectException(QueryBudgetExceeded::class);

        QueryBudget::run($connection, 800, fn () => throw $this->timeout(7, '57014'));
    }

    public function test_drivers_without_a_timeout_just_run(): void
    {
        foreach (['sqlite', 'sqlsrv'] as $driver) {
            $connection = $this->connection($driver);
            $connection->shouldNotReceive('statement');

            $this->assertSame('rows', QueryBudget::run($connection, 500, fn () => 'rows'));
        }
    }

    public function test_other_database_errors_pass_through(): void
    {
        $other = $this->timeout(1064, '42000');

        $this->assertFalse(QueryBudget::isTimeout($other));

        $this->expectException(QueryException::class);

        QueryBudget::run($this->connection('sqlite'), 500, fn () => throw $other);
    }
}
