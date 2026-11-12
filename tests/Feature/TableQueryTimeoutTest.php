<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Query\QueryBudgetExceeded;
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Tables\Columns\Summarizers\Sum;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Events\TableQueryTimedOut;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tables\TableStat;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PDOException;

class TqItem extends Model
{
    protected $table = 'tq_items';

    public $timestamps = false;

    protected $guarded = [];
}

class TqItemResource extends Resource
{
    public static function getModel(): string
    {
        return TqItem::class;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordModals(static::class)
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('copies')->summarize(Sum::make()),
            ])
            ->stats([TableStat::make('Total')->count()]);
    }
}

/**
 * The table query budget: a statement the database cancels degrades the one
 * phase that ran it (count, rows or aggregates) instead of failing the page.
 * The cancellation is faked on SQLite by throwing MySQL's error 3024.
 */
class TableQueryTimeoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tq_items', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->integer('copies')->default(1);
        });

        foreach (range(1, 25) as $i) {
            TqItem::create(['title' => "Item {$i}"]);
        }
    }

    /** Cancel every statement whose SQL contains the fragment. */
    private function cancel(string $fragment): void
    {
        DB::connection()->beforeExecuting(function (string $query, array $bindings, Connection $connection) use ($fragment): void {
            if (! str_contains(strtolower($query), $fragment)) {
                return;
            }

            $pdo            = new PDOException('cancelled');
            $pdo->errorInfo = ['HY000', 3024, 'Query execution was interrupted'];

            throw new QueryException($connection->getName() ?? 'testing', $query, $bindings, $pdo);
        });
    }

    private function table(?int $timeout = 500): Table
    {
        $table = TqItemResource::table(Table::make(TqItem::query()));

        return $timeout === null ? $table : $table->queryTimeout($timeout);
    }

    public function test_it_is_off_without_a_budget(): void
    {
        $this->cancel('count(*)');

        $this->expectException(QueryException::class);

        $this->table(null)->toData();
    }

    public function test_a_cancelled_count_falls_back_to_simple_paging(): void
    {
        Event::fake([TableQueryTimedOut::class]);
        $this->cancel('count(*)');

        $data = $this->table()->toData();

        $this->assertFalse($data->timedOut);
        $this->assertCount(10, $data->records);
        $this->assertNull($data->pagination?->total);
        $this->assertTrue($data->pagination?->hasMore);
        Event::assertDispatched(TableQueryTimedOut::class, fn (TableQueryTimedOut $e): bool => $e->phase === 'count' && $e->timeoutMs === 500 && $e->model === TqItem::class);
    }

    public function test_cancelled_rows_render_a_timed_out_table(): void
    {
        Event::fake([TableQueryTimedOut::class]);
        $this->cancel('select * from "tq_items"');

        $data = $this->table()->toData();

        $this->assertTrue($data->timedOut);
        $this->assertSame([], $data->records);
        $this->assertNull($data->pagination);
        Event::assertDispatched(TableQueryTimedOut::class, fn (TableQueryTimedOut $e): bool => $e->phase === 'rows');
    }

    public function test_the_event_never_carries_the_search_term(): void
    {
        Event::fake([TableQueryTimedOut::class]);
        request()->merge(['search' => 'Item 1']);
        $this->cancel('select * from "tq_items"');

        $this->table()->toData();

        Event::assertDispatched(TableQueryTimedOut::class, function (TableQueryTimedOut $e): bool {
            return ! str_contains(json_encode(get_object_vars($e), JSON_THROW_ON_ERROR), 'Item 1');
        });
    }

    public function test_the_event_carries_the_team_key_when_teams_are_on(): void
    {
        config(['kinetix.teams' => true]);
        Schema::create('tq_users', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('current_team_id')->nullable();
        });
        Schema::create('tq_teams', function (Blueprint $table) {
            $table->increments('id');
        });

        $user = new class extends User
        {
            protected $table = 'tq_users';

            public $timestamps = false;

            protected $guarded = [];

            public function currentTeam(): BelongsTo
            {
                return $this->belongsTo(TqTeam::class, 'current_team_id');
            }
        };
        $team = TqTeam::create([]);
        $this->actingAs($user::create(['current_team_id' => $team->id]));

        Event::fake([TableQueryTimedOut::class]);
        $this->cancel('select * from "tq_items"');

        $this->table()->toData();

        Event::assertDispatched(TableQueryTimedOut::class, fn (TableQueryTimedOut $e): bool => $e->teamKey === $team->id);
    }

    public function test_cancelled_inline_aggregates_come_back_empty_while_rows_load(): void
    {
        $this->cancel('sum(');

        $data = TqItemResource::table(Table::make(TqItem::query()))->queryTimeout(500)->toData();

        $this->assertFalse($data->timedOut);
        $this->assertCount(10, $data->records);
        $this->assertSame([], $data->stats);
        $this->assertSame([], $data->summaries);
    }

    public function test_cancelled_deferred_aggregates_report_timed_out(): void
    {
        Event::fake([TableQueryTimedOut::class]);
        $this->cancel('sum(');

        $result = $this->table()->aggregates();

        $this->assertTrue($result['timed_out'] ?? false);
        $this->assertSame([], $result['stats']);
        $this->assertSame([], $result['summaries']);
        Event::assertDispatched(TableQueryTimedOut::class, fn (TableQueryTimedOut $e): bool => $e->phase === 'aggregates');
    }

    public function test_the_aggregates_endpoint_passes_timed_out_through(): void
    {
        config(['kinetix.tables.query_timeout' => 500]);
        $descriptor = TqItemResource::table(Table::make(TqItem::query()))->deferStats()->toData()->aggregatesDescriptor;
        $this->cancel('sum(');

        $this->postJson(route('kinetix.tables.aggregates'), ['descriptor' => $descriptor])
            ->assertOk()
            ->assertJsonPath('timed_out', true);
    }

    public function test_a_healthy_aggregates_response_has_no_timed_out_flag(): void
    {
        $this->assertArrayNotHasKey('timed_out', $this->table()->aggregates());
    }

    public function test_the_config_budget_applies_and_zero_turns_it_off_per_table(): void
    {
        config(['kinetix.tables.query_timeout' => 500]);
        $this->cancel('select * from "tq_items"');

        $this->assertTrue($this->table(null)->toData()->timedOut);

        $this->expectException(QueryException::class);

        $this->table(0)->toData();
    }

    public function test_the_paginate_helper_degrades_a_cancelled_count(): void
    {
        $table = Table::make(TqItem::query())->columns([TextColumn::make('title')])->queryTimeout(500);

        $this->cancel('count(*)');

        $page = $table->paginate(TqItem::query());

        $this->assertCount(10, $page->items());
        $this->assertFalse(method_exists($page, 'total'));
    }

    public function test_the_paginate_helper_rethrows_a_cancelled_page(): void
    {
        $table = Table::make(TqItem::query())->columns([TextColumn::make('title')])->queryTimeout(500);

        $this->cancel('select * from "tq_items"');

        $this->expectException(QueryBudgetExceeded::class);

        $table->paginate(TqItem::query());
    }

    public function test_a_table_without_aggregates_opens_no_aggregates_budget(): void
    {
        $plain = TqSpyTable::make(TqItem::query())->columns([TextColumn::make('title')])->queryTimeout(500);
        $plain->toData();

        $this->assertSame(['count', 'rows'], $plain->phases);

        $withStats = TqSpyTable::make(TqItem::query())
            ->columns([TextColumn::make('title')])
            ->stats([TableStat::make('Total')->count()])
            ->queryTimeout(500);
        $withStats->toData();

        $this->assertSame(['aggregates', 'count', 'rows'], $withStats->phases);

        $plain->phases = [];
        $plain->aggregates();

        $this->assertSame([], $plain->phases);
    }
}

/** Records which phases open a budget, since SQLite runs none. */
class TqSpyTable extends Table
{
    /** @var list<string> */
    public array $phases = [];

    protected function withinBudget(Builder $query, string $phase, callable $fn): mixed
    {
        $this->phases[] = $phase;

        return parent::withinBudget($query, $phase, $fn);
    }
}

class TqTeam extends Model
{
    protected $table = 'tq_teams';

    public $timestamps = false;

    protected $guarded = [];
}
