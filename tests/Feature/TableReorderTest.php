<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

class ReorderWidget extends Model
{
    protected $table = 'reorder_widgets';

    public $timestamps = false;

    protected $guarded = [];
}

class ReorderWidgetPolicy
{
    public function update(?User $user, ReorderWidget $widget): bool
    {
        return $widget->name !== 'Locked';
    }
}

class TableReorderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('reorder_widgets', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('sort_order')->default(0);
        });

        foreach (['A', 'B', 'C'] as $i => $name) {
            ReorderWidget::create(['name' => $name, 'sort_order' => $i + 1]);
        }
    }

    private function reorderableToken(): string
    {
        return Table::make(ReorderWidget::query())
            ->reorderable('sort_order')
            ->columns([TextColumn::make('name')])
            ->toData()
            ->model;
    }

    private function plainToken(): string
    {
        return Table::make(ReorderWidget::query())
            ->columns([TextColumn::make('name')])
            ->toData()
            ->model;
    }

    public function test_reorderable_flag_is_serialized(): void
    {
        $data = Table::make(ReorderWidget::query())->reorderable()->toData();

        $this->assertTrue($data->reorderable);
        $this->assertFalse(Table::make(ReorderWidget::query())->toData()->reorderable);
    }

    public function test_reorder_persists_the_new_order(): void
    {
        // New order: C, A, B (ids 3, 1, 2).
        $response = $this->postJson(route('kinetix.tables.reorder'), [
            'model' => $this->reorderableToken(),
            'ids'   => [3, 1, 2],
        ]);

        $response->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame(1, ReorderWidget::find(3)->sort_order);
        $this->assertSame(2, ReorderWidget::find(1)->sort_order);
        $this->assertSame(3, ReorderWidget::find(2)->sort_order);
    }

    public function test_reorder_rejects_a_batch_over_the_cap(): void
    {
        config()->set('kinetix.tables.reorder_max', 2);

        $response = $this->postJson(route('kinetix.tables.reorder'), [
            'model' => $this->reorderableToken(),
            'ids'   => [3, 1, 2], // 3 ids > cap of 2
        ]);

        $response->assertStatus(422);

        // Nothing was rewritten — the original order stands.
        $this->assertSame(1, ReorderWidget::find(1)->sort_order);
        $this->assertSame(2, ReorderWidget::find(2)->sort_order);
        $this->assertSame(3, ReorderWidget::find(3)->sort_order);
    }

    public function test_reorder_is_rejected_when_table_is_not_reorderable(): void
    {
        $response = $this->postJson(route('kinetix.tables.reorder'), [
            'model' => $this->plainToken(),
            'ids'   => [3, 1, 2],
        ]);

        $response->assertForbidden();
        // Order untouched.
        $this->assertSame(1, ReorderWidget::find(1)->sort_order);
    }

    public function test_reorder_rejects_a_tampered_token(): void
    {
        $this->postJson(route('kinetix.tables.reorder'), [
            'model' => 'not-a-valid-token',
            'ids'   => [1, 2, 3],
        ])->assertStatus(400);
    }

    public function test_reorderable_table_defaults_to_sort_order(): void
    {
        ReorderWidget::find(3)->update(['sort_order' => 0]); // bubble C to the top

        $data = Table::make(ReorderWidget::query())
            ->reorderable('sort_order')
            ->columns([TextColumn::make('name')])
            ->toData();

        $this->assertSame('C', $data->records[0]->values['name']);
    }

    /**
     * @param list<int|string|null> $positions one row per position, named R1, R2, …
     */
    private function seedRows(array $positions): void
    {
        ReorderWidget::query()->delete();

        foreach ($positions as $i => $position) {
            ReorderWidget::create(['id' => $i + 1, 'name' => 'R'.($i + 1), 'sort_order' => $position]);
        }
    }

    /**
     * @return array<int, int|null> id => position
     */
    private function positions(): array
    {
        return ReorderWidget::query()->orderBy('id')->pluck('sort_order', 'id')->all();
    }

    private function reorder(array $ids): TestResponse
    {
        return $this->postJson(route('kinetix.tables.reorder'), [
            'model' => $this->reorderableToken(),
            'ids'   => $ids,
        ]);
    }

    /**
     * Page two's rows were numbered 1..10 — the positions page one's rows
     * hold — so a drag there shuffled the two pages together.
     */
    public function test_a_reorder_on_page_two_keeps_page_one_in_place(): void
    {
        $this->seedRows(range(1, 25));

        $this->reorder([20, 19, 18, 17, 16, 15, 14, 13, 12, 11])->assertOk();

        $positions = $this->positions();

        foreach (range(1, 10) as $id) {
            $this->assertSame($id, $positions[$id]);
        }

        foreach (range(11, 20) as $id) {
            $this->assertSame(31 - $id, $positions[$id]);
        }

        foreach (range(21, 25) as $id) {
            $this->assertSame($id, $positions[$id]);
        }
    }

    public function test_a_filtered_view_trades_only_the_positions_it_shows(): void
    {
        $this->seedRows([10, 20, 30, 40, 50, 60]);

        // A search showing rows 2 and 5 only: 5 moves above 2.
        $this->reorder([5, 2])->assertOk();

        $this->assertSame(
            [1 => 10, 2 => 50, 3 => 30, 4 => 40, 5 => 20, 6 => 60],
            $this->positions(),
        );
    }

    /**
     * A fresh column of zeros has no positions to trade: the list is numbered
     * in the order the table shows it (zeros, then by key), with the dragged
     * rows placed where they were.
     */
    public function test_an_unnumbered_list_is_numbered_in_the_order_it_was_shown(): void
    {
        $this->seedRows(array_fill(0, 25, 0));

        // Page two (ids 11..20 in key order): 15 dragged to its top.
        $this->reorder([15, 11, 12, 13, 14, 16, 17, 18, 19, 20])->assertOk();

        $expected = [];

        foreach ([...range(1, 10), 15, 11, 12, 13, 14, ...range(16, 25)] as $index => $id) {
            $expected[$id] = $index + 1;
        }

        ksort($expected);

        $this->assertSame($expected, $this->positions());
    }

    /**
     * A row outside the window sharing a position (a list numbered per group,
     * a scope wider than the rows shown) keeps it, and the tie orders by key.
     * Numbering the whole list for it wrote rows the user never saw.
     */
    public function test_a_position_shared_with_a_row_outside_the_window_is_left_alone(): void
    {
        // Rows 2 and 3 share position 2; the page shows rows 1 and 2 only.
        $this->seedRows([1, 2, 2, 3]);

        $this->reorder([2, 1])->assertOk();

        $this->assertSame([1 => 2, 2 => 1, 3 => 2, 4 => 3], $this->positions());
    }

    public function test_an_unnumbered_list_over_the_cap_is_refused_untouched(): void
    {
        config()->set('kinetix.tables.reorder_max', 5);
        $this->seedRows(array_fill(0, 8, 0));

        $this->reorder([2, 1])
            ->assertStatus(422)
            ->assertJsonPath('message', __('kinetix.table_reorder_unnumbered'));

        $this->assertSame(array_fill_keys(range(1, 8), 0), $this->positions());
    }

    public function test_a_repeated_id_takes_one_position(): void
    {
        $this->reorder([3, 3, 1, 2])->assertOk();

        $this->assertSame([1 => 2, 2 => 3, 3 => 1], $this->positions());
    }

    public function test_rows_that_keep_their_position_are_not_saved(): void
    {
        $saved = [];
        ReorderWidget::saved(function (ReorderWidget $widget) use (&$saved): void {
            $saved[] = $widget->getKey();
        });

        // A, C, B: A keeps position 1.
        $this->reorder([1, 3, 2])->assertOk();

        sort($saved);
        $this->assertSame([2, 3], $saved);
    }

    public function test_numbering_the_list_needs_write_access_to_every_row_it_moves(): void
    {
        Gate::policy(ReorderWidget::class, ReorderWidgetPolicy::class);
        $this->seedRows([0, 0, 0]);
        ReorderWidget::query()->whereKey(3)->update(['name' => 'Locked']);

        // Dragging 2 above 1 numbers row 3 too — which this user may not write.
        $this->reorder([2, 1])->assertForbidden();

        $this->assertSame([1 => 0, 2 => 0, 3 => 0], $this->positions());
    }

    /**
     * A row the user may not write, shown on the page but keeping its
     * position, no longer blocks the drag: only rows that move are checked.
     */
    public function test_a_locked_row_that_keeps_its_position_doesnt_block_the_reorder(): void
    {
        Gate::policy(ReorderWidget::class, ReorderWidgetPolicy::class);
        ReorderWidget::query()->whereKey(1)->update(['name' => 'Locked']);

        // A (locked) stays first; C moves above B.
        $this->reorder([1, 3, 2])->assertOk();

        $this->assertSame([1 => 1, 2 => 3, 3 => 2], $this->positions());
    }

    /**
     * A table over `whereIn()` (which the write scope can't capture), its
     * groups numbered separately: a drag used to renumber every row of the
     * model, other groups included.
     */
    public function test_a_table_scoped_by_where_in_doesnt_renumber_rows_it_doesnt_show(): void
    {
        Schema::table('reorder_widgets', fn (Blueprint $table) => $table->integer('group_id')->default(1));
        $this->seedRows([1, 2, 1, 2]);
        ReorderWidget::query()->whereKey([3, 4])->update(['group_id' => 2]);

        $token = Table::make(ReorderWidget::query()->whereIn('group_id', [1]))
            ->reorderable('sort_order')
            ->columns([TextColumn::make('name')])
            ->toData()
            ->model;

        $this->postJson(route('kinetix.tables.reorder'), ['model' => $token, 'ids' => [2, 1]])->assertOk();

        $this->assertSame([1 => 2, 2 => 1, 3 => 1, 4 => 2], $this->positions());
    }

    public function test_rows_sharing_a_position_are_ordered_by_key(): void
    {
        $this->seedRows([0, 0, 0]);
        DB::enableQueryLog();

        Table::make(ReorderWidget::query())
            ->reorderable('sort_order')
            ->columns([TextColumn::make('name')])
            ->toData();

        $select = collect(DB::getQueryLog())
            ->pluck('query')
            ->first(static fn (string $sql): bool => str_contains($sql, 'order by'));

        $this->assertStringContainsString('order by "sort_order" asc, "reorder_widgets"."id" asc', (string) $select);
    }
}
