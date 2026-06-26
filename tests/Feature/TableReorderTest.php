<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ReorderWidget extends Model
{
    protected $table = 'reorder_widgets';

    public $timestamps = false;

    protected $guarded = [];
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
}
