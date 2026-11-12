<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Integration;

use Happones\Kinetix\Query\KinetixQuery;
use Happones\Kinetix\Query\SearchMode;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SmeDoc extends Model
{
    protected $connection = 'engine';

    protected $table = 'sme_docs';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['qty' => 'integer'];
}

class SmeSmall extends Model
{
    protected $connection = 'engine';

    protected $table = 'sme_small';

    protected $guarded = [];

    protected $casts = ['qty' => 'integer', 'code' => 'integer', 'seen_at' => 'datetime', 'ratio' => 'float'];
}

/**
 * Runs the search modes against a real engine. Skipped unless
 * KINETIX_DB_INTEGRATION is mysql, mariadb or pgsql, with DB_HOST, DB_PORT,
 * DB_DATABASE, DB_USERNAME and DB_PASSWORD pointing at a disposable database:
 *
 *   KINETIX_DB_INTEGRATION=pgsql DB_HOST=... DB_PORT=5432 DB_DATABASE=kinetix \
 *   DB_USERNAME=postgres DB_PASSWORD=secret \
 *   vendor/bin/testbench package:test tests/Integration/SearchModeEngineTest.php
 */
class SearchModeEngineTest extends TestCase
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
        $schema->dropIfExists('sme_docs');
        $schema->dropIfExists('sme_small');
        $schema->create('sme_small', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('qty')->default(0);
            $table->string('code')->default('');
            $table->dateTime('seen_at')->nullable();
            $table->double('ratio')->default(0);
            $table->timestamps();
        });
        $this->connection()->table('sme_small')->insert([
            ['qty' => 7, 'code' => '42', 'seen_at' => '2026-01-02 00:00:00', 'ratio' => 1.5, 'created_at' => '2026-03-04 05:06:07', 'updated_at' => '2026-03-04 05:06:07'],
            ['qty' => 8, 'code' => '43', 'seen_at' => '2026-01-02 10:30:00', 'ratio' => 2.5, 'created_at' => '2025-03-04 05:06:07', 'updated_at' => '2025-03-04 05:06:07'],
        ]);
        $schema->create('sme_docs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->text('body');
            $table->integer('qty')->default(0);
        });

        $rows = [
            ['name' => '100% pure', 'body' => 'The quick brown fox jumps', 'qty' => 5],
            ['name' => '100x pure', 'body' => 'A slow green turtle walks', 'qty' => 7],
            ['name' => 'a_b', 'body' => 'Database engines index text', 'qty' => 9],
            ['name' => 'axb', 'body' => 'Searching large tables is slow', 'qty' => 5],
        ];

        foreach (range(1, 3000) as $i) {
            $rows[] = ['name' => 'filler '.str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'body' => "Filler paragraph number {$i} about nothing", 'qty' => $i];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $this->connection()->table('sme_docs')->insert($chunk);
        }
    }

    protected function tearDown(): void
    {
        if ($this->engine !== '') {
            Schema::connection('engine')->dropIfExists('sme_docs');
            Schema::connection('engine')->dropIfExists('sme_small');
        }

        parent::tearDown();
    }

    private function connection(): Connection
    {
        return DB::connection('engine');
    }

    /** @return list<string> */
    private function names(string $term, array $columns): array
    {
        return KinetixQuery::search(SmeDoc::query(), $term, $columns)->orderBy('id')->pluck('name')->all();
    }

    public function test_a_like_on_a_bigint_key_is_what_breaks_postgres(): void
    {
        $sql = 'select * from sme_docs where "id" like ? escape \'!\'';

        if ($this->engine !== 'pgsql') {
            $this->connection()->select(str_replace('"id"', '`id`', $sql), ['%1%']);
            $this->assertTrue(true);

            return;
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/operator does not exist/i');

        $this->connection()->select($sql, ['%1%']);
    }

    public function test_the_default_relation_picker_search_on_the_key_works_on_every_engine(): void
    {
        // RelationManagerController searches the key column when a manager
        // declares no search columns: a bare Contains on a bigint. Before the
        // text cast this raised "operator does not exist: bigint ~~ unknown"
        // on PostgreSQL.
        $ids = KinetixQuery::search(SmeDoc::query(), '3000', ['id'])->pluck('id')->all();

        $this->assertContains(3000, array_map('intval', $ids));
    }

    public function test_prefix_and_contains_on_numbers_and_casts_work(): void
    {
        $this->assertSame(['100% pure', '100x pure'], array_slice($this->names('100', ['name' => SearchMode::Prefix]), 0, 2));
        $this->assertNotEmpty(KinetixQuery::search(SmeDoc::query(), '9', ['qty' => SearchMode::Prefix])->limit(1)->get());
    }

    public function test_wildcards_in_the_term_are_literal_in_every_mode(): void
    {
        $this->assertSame(['100% pure'], $this->names('100%', ['name' => SearchMode::Prefix]));
        $this->assertSame(['100% pure'], $this->names('0% p', ['name' => SearchMode::Contains]));
        $this->assertSame(['a_b'], $this->names('a_b', ['name' => SearchMode::Prefix]));
        $this->assertSame(['a_b'], $this->names('a_b', ['name' => SearchMode::Contains]));
    }

    public function test_exact_matches_and_its_guard_never_errors(): void
    {
        $this->assertSame(['axb'], $this->names('axb', ['name' => SearchMode::Exact]));
        $this->assertSame([], $this->names('not a number', ['id' => SearchMode::Exact]));
        $this->assertSame([], $this->names('abc', ['qty' => SearchMode::Exact]));
        $this->assertSame(['a_b', 'filler 00009'], $this->names('9', ['qty' => SearchMode::Exact]));
    }

    public function test_prefix_uses_an_index_range_scan(): void
    {
        if ($this->engine === 'pgsql') {
            $this->connection()->statement('CREATE INDEX sme_docs_name_prefix ON sme_docs (name text_pattern_ops)');
            $this->connection()->statement('ANALYZE sme_docs');
            $this->connection()->statement('SET enable_seqscan = off');
        } else {
            $this->connection()->statement('CREATE INDEX sme_docs_name_prefix ON sme_docs (name)');
            $this->connection()->statement('ANALYZE TABLE sme_docs');
        }

        $query = KinetixQuery::search(SmeDoc::query(), 'filler 0001', ['name' => SearchMode::Prefix]);
        $plan  = $this->connection()->select('EXPLAIN '.$query->toSql(), $query->getBindings());
        $text  = strtolower(json_encode($plan, JSON_THROW_ON_ERROR));

        if ($this->engine === 'pgsql') {
            $this->assertStringContainsString('index', $text);
            $this->assertStringNotContainsString('seq scan', $text);

            return;
        }

        $this->assertStringContainsString('"type":"range"', $text);
        $this->assertStringContainsString('sme_docs_name_prefix', $text);
    }

    public function test_full_text_finds_words_with_its_index(): void
    {
        if ($this->engine === 'pgsql') {
            $this->connection()->statement("CREATE INDEX sme_docs_body_fts ON sme_docs USING GIN (to_tsvector('english', body))");
        } else {
            $this->connection()->statement('ALTER TABLE sme_docs ADD FULLTEXT sme_docs_body_fts (body)');
        }

        $this->assertSame(['100% pure'], $this->names('quick fox', ['body' => SearchMode::FullText]));
        $this->assertSame([], $this->names('zzzunknownword', ['body' => SearchMode::FullText]));

        if ($this->engine === 'pgsql') {
            $this->connection()->statement('ANALYZE sme_docs');
            $this->connection()->statement('SET enable_seqscan = off');

            $query = KinetixQuery::search(SmeDoc::query(), 'quick', ['body' => SearchMode::FullText]);
            $plan  = strtolower(json_encode($this->connection()->select('EXPLAIN '.$query->toSql(), $query->getBindings()), JSON_THROW_ON_ERROR));

            $this->assertStringContainsString('sme_docs_body_fts', $plan);
        }
    }

    /** @return list<int> */
    private function small(string $term, array $columns): array
    {
        return array_map('intval', KinetixQuery::search(SmeSmall::query(), $term, $columns)->orderBy('id')->pluck('id')->all());
    }

    public function test_exact_never_errors_on_int4_columns_and_matches_correctly(): void
    {
        // Past int4 on an `increments` key and an `integer` column: no match, no error.
        $this->assertSame([], $this->small('99999999999', ['id' => SearchMode::Exact]));
        $this->assertSame([], $this->small('99999999999', ['qty' => SearchMode::Exact]));
        $this->assertSame([], $this->small('abc', ['sme_small.id' => SearchMode::Exact]));
        $this->assertSame([2], $this->small('2', ['id' => SearchMode::Exact]));
        $this->assertSame([1], $this->small('7', ['qty' => SearchMode::Exact]));
        $this->assertSame([2], $this->small('2', ['sme_small.id' => SearchMode::Exact]));
    }

    public function test_exact_never_errors_on_date_and_float_columns(): void
    {
        foreach (['2026', 'monday', '+1 week', '2026-02-30', 'tomorrow'] as $term) {
            $this->assertSame([], $this->small($term, ['seen_at' => SearchMode::Exact]), $term);
        }

        $this->assertSame([1], $this->small('2026-01-02', ['seen_at' => SearchMode::Exact]));
        $this->assertSame([2], $this->small('2026-01-02 10:30', ['seen_at' => SearchMode::Exact]));
        $this->assertSame([], $this->small('1e999', ['ratio' => SearchMode::Exact]));
        $this->assertSame([1], $this->small('1.5', ['ratio' => SearchMode::Exact]));
    }

    public function test_contains_and_prefix_work_on_dates_and_timestamps(): void
    {
        $this->assertSame([1], $this->small('2026-03', ['created_at' => SearchMode::Prefix]));
        $this->assertContains(1, $this->small('2026-01-02', ['seen_at' => SearchMode::Contains]));
        $this->assertSame([2], $this->small('2', ['id' => SearchMode::Contains]));
        $this->assertSame([1], $this->small('7', ['qty' => SearchMode::Prefix]));
    }

    public function test_exact_on_an_integer_cast_varchar_column_matches_without_error(): void
    {
        $this->assertSame([1], $this->small('42', ['code' => SearchMode::Exact]));
        $this->assertSame([], $this->small('99999999999', ['code' => SearchMode::Exact]));
        // An int4 column past its range: no match, no error.
        $this->assertSame([], $this->small('99999999999', ['qty' => SearchMode::Exact]));
        $this->assertSame([1], $this->small('7', ['qty' => SearchMode::Exact]));
    }
}
