<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Query\SearchMode;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class TsmItem extends Model
{
    protected $table = 'tsm_items';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * The toolbar search in each mode, per table and per column, with `using`.
 */
class TableSearchModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tsm_items', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('team_id')->default(1);
            $table->string('name');
            $table->string('sku')->nullable();
        });

        TsmItem::create(['name' => 'Alpha', 'sku' => 'SKU-1', 'team_id' => 1]);
        TsmItem::create(['name' => 'Alphabet', 'sku' => 'SKU-2', 'team_id' => 1]);
        TsmItem::create(['name' => 'Beta', 'sku' => 'SKU-3', 'team_id' => 2]);
    }

    private function ids(Table $table): array
    {
        return array_map(fn ($row) => $row->id, $table->toData()->records);
    }

    private function table(array $columns, ?callable $tap = null, ?Builder $query = null): Table
    {
        $table = Table::make($query ?? TsmItem::query())->columns($columns);

        if ($tap !== null) {
            $tap($table);
        }

        return $table;
    }

    public function test_the_default_is_contains(): void
    {
        request()->merge(['search' => 'lpha']);

        $this->assertSame([1, 2], $this->ids($this->table([TextColumn::make('name')->searchable()])));
    }

    public function test_the_table_mode_applies_to_every_searchable_column(): void
    {
        request()->merge(['search' => 'lpha']);

        $table = $this->table([TextColumn::make('name')->searchable()], fn (Table $t) => $t->searchMode(SearchMode::Prefix));

        $this->assertSame([], $this->ids($table));

        request()->merge(['search' => 'Alpha']);

        $this->assertSame([1, 2], $this->ids($table));
    }

    public function test_a_column_mode_overrides_the_table_mode(): void
    {
        request()->merge(['search' => 'SKU-2']);

        $table = $this->table(
            [TextColumn::make('name')->searchable(), TextColumn::make('sku')->searchable(mode: SearchMode::Exact)],
            fn (Table $t) => $t->searchMode(SearchMode::Prefix),
        );

        $this->assertSame([2], $this->ids($table));
    }

    public function test_using_replaces_the_match_and_binds_the_term(): void
    {
        request()->merge(['search' => 'sku-3']);

        $table = $this->table([
            TextColumn::make('name')->searchable(),
            TextColumn::make('sku')->searchable(using: fn (Builder $q, string $term) => $q->where('sku', strtoupper($term))),
        ]);

        $this->assertSame([3], $this->ids($table));
    }

    public function test_an_or_where_inside_using_cannot_widen_past_the_scope(): void
    {
        request()->merge(['search' => 'anything']);

        $table = $this->table(
            [TextColumn::make('sku')->searchable(using: fn (Builder $q, string $term) => $q->where('sku', 'SKU-1')->orWhere('id', '>', 0))],
            query: TsmItem::query()->where('team_id', 1),
        );

        $this->assertSame([1, 2], $this->ids($table));
    }

    public function test_when_every_column_is_skipped_nothing_matches(): void
    {
        request()->merge(['search' => 'letters']);

        $table = $this->table([TextColumn::make('id')->searchable(mode: SearchMode::Exact)]);

        $this->assertSame([], $this->ids($table));
    }

    public function test_no_searchable_columns_still_ignores_the_search(): void
    {
        request()->merge(['search' => 'zzz']);

        $this->assertSame([1, 2, 3], $this->ids($this->table([TextColumn::make('name')])));
    }

    public function test_the_aggregate_signature_follows_the_mode(): void
    {
        $signature = fn (Table $t): array => (new class($t)
        {
            public function __construct(private Table $t) {}

            /** @return array<string, mixed> */
            public function get(): array
            {
                return (fn () => $this->aggregateSignature())->call($this->t);
            }
        })->get();

        $plain   = $signature($this->table([TextColumn::make('name')->searchable()]));
        $prefix  = $signature($this->table([TextColumn::make('name')->searchable(mode: SearchMode::Prefix)]));
        $custom  = $signature($this->table([TextColumn::make('name')->searchable(using: fn (Builder $q, string $t) => $q)]));
        $tableLv = $signature($this->table([TextColumn::make('name')->searchable()], fn (Table $t) => $t->searchMode(SearchMode::Exact)));

        $this->assertNotSame($plain, $prefix);
        $this->assertNotSame($plain, $custom);
        $this->assertNotSame($prefix, $custom);
        $this->assertNotSame($plain, $tableLv);
        $this->assertSame($plain, $signature($this->table([TextColumn::make('name')->searchable()])));
    }
}
