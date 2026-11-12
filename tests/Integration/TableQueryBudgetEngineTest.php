<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Integration;

use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TqeItem extends Model
{
    protected $connection = 'engine';

    protected $table = 'tqe_items';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * Runs the table query timeout against a real engine. Skipped unless
 * KINETIX_DB_INTEGRATION is mysql, mariadb or pgsql, with DB_HOST, DB_PORT,
 * DB_DATABASE, DB_USERNAME and DB_PASSWORD pointing at a disposable database:
 *
 *   KINETIX_DB_INTEGRATION=pgsql DB_HOST=... DB_PORT=5432 DB_DATABASE=kinetix \
 *   DB_USERNAME=postgres DB_PASSWORD=secret \
 *   vendor/bin/testbench package:test tests/Integration/TableQueryBudgetEngineTest.php
 *
 * The slowness is injected in the WHERE clause: a heavy uncorrelated subquery
 * slows every statement, and a per-row predicate (a sleep on PostgreSQL, a
 * BENCHMARK on MySQL and MariaDB) makes the count over every row run far past
 * the budget while a page's LIMIT stops early.
 */
class TableQueryBudgetEngineTest extends TestCase
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

        $schema = Schema::connection('engine');
        $schema->dropIfExists('tqe_items');
        $schema->create('tqe_items', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
        });

        $rows = [];

        foreach (range(1, 300) as $i) {
            $rows[] = ['title' => "Item {$i}"];
        }

        $this->connection()->table('tqe_items')->insert($rows);
    }

    protected function tearDown(): void
    {
        if ($this->engine !== '') {
            Schema::connection('engine')->dropIfExists('tqe_items');
        }

        parent::tearDown();
    }

    private function connection(): Connection
    {
        return DB::connection('engine');
    }

    /** The session value the budget touches, as the engine reports it. */
    private function sessionValue(): string
    {
        $pdo   = $this->connection()->getReadPdo();
        $query = $this->engine === 'pgsql'
            ? 'SHOW statement_timeout'
            : 'SELECT @@SESSION.'.($this->engine === 'mariadb' ? 'max_statement_time' : 'max_execution_time');

        $statement = $pdo->query($query);
        $this->assertNotFalse($statement);

        return (string) $statement->fetchColumn();
    }

    /** An uncorrelated predicate that takes the engine many seconds, evaluated once per statement. */
    private function slowFragment(): string
    {
        if ($this->engine === 'pgsql') {
            return '(SELECT count(*) FROM generate_series(1, 300000000)) > 0';
        }

        return '(SELECT COUNT(*) FROM information_schema.columns a, information_schema.columns b, information_schema.columns c) > 0';
    }

    private function table(): Table
    {
        return Table::make(TqeItem::query())
            ->columns([TextColumn::make('title')])
            ->queryTimeout(300);
    }

    /**
     * Costs about 10 ms per evaluated row (pg_sleep(0.01); BENCHMARK(30000)
     * measured at 9-12 ms on MySQL 8.4 and MariaDB 11), so the page (a LIMIT
     * of 10 stops after 11 rows, ~0.15 s) stays far inside the 700 ms budget
     * while the count (all 300 rows, ~3 s) is several times over it.
     */
    private function perRowSlowFragment(): string
    {
        if ($this->engine === 'pgsql') {
            return 'length(pg_sleep(0.01)::text) >= 0';
        }

        return "BENCHMARK(30000, MD5(CONCAT('x', id))) = 0";
    }

    public function test_a_cancelled_count_still_loads_the_rows_without_a_total(): void
    {
        $before = $this->sessionValue();

        $data = Table::make(TqeItem::query()->whereRaw($this->perRowSlowFragment()))
            ->columns([TextColumn::make('title')])
            ->queryTimeout(700)
            ->toData();

        $this->assertFalse($data->timedOut);
        $this->assertCount(10, $data->records);
        $this->assertNull($data->pagination?->total);
        $this->assertTrue($data->pagination?->hasMore);
        $this->assertSame($before, $this->sessionValue());

        // On PostgreSQL the cancelled count aborted its own transaction only:
        // the connection serves the next statement.
        $this->assertSame(300, $this->connection()->table('tqe_items')->count());
    }

    public function test_slow_rows_render_a_timed_out_table_and_restore_the_session(): void
    {
        $before = $this->sessionValue();

        $data = Table::make(TqeItem::query()->whereRaw($this->slowFragment()))
            ->columns([TextColumn::make('title')])
            ->queryTimeout(300)
            ->toData();

        $this->assertTrue($data->timedOut);
        $this->assertSame([], $data->records);
        $this->assertNull($data->pagination);
        $this->assertSame($before, $this->sessionValue());
    }

    public function test_a_healthy_table_is_untouched_by_the_budget(): void
    {
        $data = $this->table()->toData();

        $this->assertFalse($data->timedOut);
        $this->assertCount(10, $data->records);
        $this->assertSame(300, $data->pagination?->total);
    }
}
