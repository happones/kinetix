<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Tables\Columns\Summarizers\Sum;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tables\TableStat;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AggBook extends Model
{
    protected $table = 'agg_books';

    public $timestamps = false;

    protected $guarded = [];
}

class AggBookResource extends Resource
{
    public static function getModel(): string
    {
        return AggBook::class;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordModals(static::class)
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('copies')->summarize(Sum::make()),
            ])
            ->stats([
                TableStat::make('Total')->count(),
            ]);
    }
}

/**
 * `Table::deferStats()` keeps the KPI stats + column summaries off the first
 * paint (empty + a signed descriptor); the aggregates endpoint rebuilds the
 * table from its resource and returns them. Only resource-backed tables defer.
 */
class TableDeferStatsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('agg_books', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->integer('copies')->default(1);
        });

        AggBook::create(['title' => 'A', 'copies' => 2]);
        AggBook::create(['title' => 'B', 'copies' => 5]);
    }

    private function deferredTable(): Table
    {
        return AggBookResource::table(Table::make(AggBook::query()))->deferStats();
    }

    public function test_deferred_table_ships_empty_aggregates_and_a_descriptor(): void
    {
        $data = $this->deferredTable()->toData();

        $this->assertTrue($data->deferStats);
        $this->assertNotNull($data->aggregatesDescriptor);
        $this->assertSame([], $data->stats);
        $this->assertSame([], $data->summaries);
        $this->assertFalse($data->hasSummaries);
    }

    public function test_an_inline_table_ignores_defer_and_ships_aggregates_inline(): void
    {
        // No resource → not reconstructible → deferStats() is a no-op.
        $data = Table::make(AggBook::query())
            ->columns([TextColumn::make('copies')->summarize(Sum::make())])
            ->stats([TableStat::make('Total')->count()])
            ->deferStats()
            ->toData();

        $this->assertFalse($data->deferStats);
        $this->assertNull($data->aggregatesDescriptor);
        $this->assertNotSame([], $data->stats);
        $this->assertTrue($data->hasSummaries);
    }

    public function test_the_endpoint_returns_the_computed_aggregates(): void
    {
        $descriptor = $this->deferredTable()->toData()->aggregatesDescriptor;

        $response = $this->postJson(route('kinetix.tables.aggregates'), [
            'descriptor' => $descriptor,
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('hasSummaries'));
        // Sum of copies = 7; the total stat = 2 rows.
        $this->assertSame('7', (string) $response->json('summaries.copies.0.value'));
        $this->assertSame('2', (string) $response->json('stats.0.value'));
    }

    public function test_a_tampered_descriptor_is_rejected(): void
    {
        $response = $this->postJson(route('kinetix.tables.aggregates'), [
            'descriptor' => 'not-a-valid-token',
        ]);

        $response->assertStatus(400);
    }

    /**
     * The endpoint rebuilds from the resource. A page that narrowed the base
     * query used to defer anyway, and its totals then covered rows it never
     * showed (here: 2 books instead of 1). Now it computes them inline.
     */
    public function test_a_page_that_narrows_the_query_computes_its_aggregates_inline(): void
    {
        $data = AggBookResource::table(Table::make(AggBook::query()->where('title', 'A')))
            ->deferStats()
            ->toData();

        $this->assertFalse($data->deferStats);
        $this->assertNull($data->aggregatesDescriptor);
        $this->assertSame('2', (string) $data->summaries['copies'][0]->value);
        $this->assertSame('1', (string) $data->stats[0]->value);
    }

    public function test_a_page_that_adds_stats_computes_its_aggregates_inline(): void
    {
        $data = AggBookResource::table(Table::make(AggBook::query()))
            ->stats([
                TableStat::make('Total')->count(),
                TableStat::make('Copies')->sum('copies'),
            ])
            ->deferStats()
            ->toData();

        $this->assertFalse($data->deferStats);
        $this->assertCount(2, $data->stats);
    }
}
