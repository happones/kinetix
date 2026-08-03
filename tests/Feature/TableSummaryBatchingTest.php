<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tables\Columns\Summarizers\Average;
use Happones\Kinetix\Tables\Columns\Summarizers\Count;
use Happones\Kinetix\Tables\Columns\Summarizers\Range;
use Happones\Kinetix\Tables\Columns\Summarizers\Sum;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SummaryOrder extends Model
{
    protected $table = 'orders';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * Each summarizer used to run its own aggregate query, so a footer with sum +
 * average + count scanned the filtered table three times — on exactly the table
 * where a scan is expensive. Plain aggregates now share one query.
 */
class TableSummaryBatchingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('orders', function (Blueprint $table) {
            $table->increments('id');
            $table->string('status');
            $table->integer('amount');
        });

        foreach ([10, 20, 30, 40] as $i => $amount) {
            SummaryOrder::create([
                'status' => $i % 2 === 0 ? 'paid' : 'pending',
                'amount' => $amount,
            ]);
        }
    }

    /**
     * @param array<int, mixed> $summarizers
     */
    private function tableWith(array $summarizers): Table
    {
        return Table::make(SummaryOrder::query())
            ->columns([
                TextColumn::make('amount')->summarize($summarizers),
                TextColumn::make('status'),
            ]);
    }

    private function countAggregateQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $queries = collect(DB::getQueryLog())
            ->map(fn (array $q): string => strtolower((string) $q['query']))
            // The paginator's own `count(*) as "aggregate"` is not a summary.
            ->reject(fn (string $sql): bool => preg_match('/count\(\*\) as ["`\[]?aggregate/', $sql) === 1)
            ->filter(fn (string $sql): bool => preg_match('/\b(sum|avg|min|max|count)\(/', $sql) === 1)
            ->count();

        DB::disableQueryLog();

        return $queries;
    }

    public function test_four_summarizers_share_one_query(): void
    {
        $queries = $this->countAggregateQueries(function (): void {
            $this->tableWith([
                Sum::make()->label('Total'),
                Average::make()->label('Avg'),
                Count::make()->label('Orders'),
                Range::make()->label('Spread'),
            ])->toArray();
        });

        $this->assertSame(1, $queries, 'Summarizers should share a single aggregate scan.');
    }

    public function test_the_values_are_the_same_as_before_batching(): void
    {
        $summaries = $this->tableWith([
            Sum::make()->label('Total'),
            Average::make()->label('Avg'),
            Count::make()->label('Orders'),
            Range::make()->label('Spread'),
        ])->toArray()['summaries']['amount'];

        $values = array_column($summaries, 'value', 'label');

        $this->assertSame('100', $values['Total']);
        $this->assertSame('25', $values['Avg']);
        $this->assertSame('4', $values['Orders']);
        $this->assertSame('10 – 40', $values['Spread']);
    }

    public function test_the_aggregates_respect_the_tables_filters(): void
    {
        request()->merge(['search' => 'paid']);

        $summaries = Table::make(SummaryOrder::query())
            ->columns([
                TextColumn::make('amount')->summarize([Sum::make()->label('Total')]),
                TextColumn::make('status')->searchable(),
            ])
            ->toArray()['summaries']['amount'];

        // Only the two "paid" rows: 10 + 30.
        $this->assertSame('40', array_column($summaries, 'value', 'label')['Total']);
    }

    public function test_an_empty_average_still_renders_zero(): void
    {
        SummaryOrder::query()->delete();

        $summaries = $this->tableWith([Average::make()->label('Avg')])->toArray()['summaries']['amount'];

        $this->assertSame('0', array_column($summaries, 'value', 'label')['Avg']);
    }

    /**
     * A `query()` scope narrows the dataset, so that summarizer cannot share the
     * batched scan — it keeps its own query, and its value must stay correct.
     */
    public function test_a_scoped_summarizer_keeps_its_own_query(): void
    {
        $summaries = $this->tableWith([
            Sum::make()->label('All'),
            Sum::make()->label('Paid only')->query(fn ($query) => $query->where('status', 'paid')),
        ])->toArray()['summaries']['amount'];

        $values = array_column($summaries, 'value', 'label');

        $this->assertSame('100', $values['All']);
        $this->assertSame('40', $values['Paid only']);
    }

    public function test_a_custom_using_callback_is_not_batched(): void
    {
        $summaries = $this->tableWith([
            Sum::make()->label('Total'),
            Sum::make()->label('Rows')->using(fn ($query) => $query->count()),
        ])->toArray()['summaries']['amount'];

        $values = array_column($summaries, 'value', 'label');

        $this->assertSame('100', $values['Total']);
        $this->assertSame('4', $values['Rows']);
    }

    public function test_summarizers_on_different_columns_share_the_same_query(): void
    {
        $queries = $this->countAggregateQueries(function (): void {
            Table::make(SummaryOrder::query())
                ->columns([
                    TextColumn::make('amount')->summarize([Sum::make()->label('Total')]),
                    TextColumn::make('id')->summarize([Count::make()->label('Rows')]),
                ])
                ->toArray();
        });

        $this->assertSame(1, $queries);
    }

    public function test_no_aggregate_query_runs_without_summarizers(): void
    {
        $queries = $this->countAggregateQueries(function (): void {
            Table::make(SummaryOrder::query())
                ->columns([TextColumn::make('amount')])
                ->toArray();
        });

        $this->assertSame(0, $queries);
    }

    public function test_a_hidden_summarizer_is_omitted_from_the_payload(): void
    {
        $data = $this->tableWith([Sum::make()->label('Total')->hidden()])->toArray();

        $this->assertArrayNotHasKey('amount', $data['summaries']);
    }
}
