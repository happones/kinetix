<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Columns\ToggleColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class SecWidget extends Model
{
    protected $table = 'sec_widgets';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['is_active' => 'bool', 'is_admin' => 'bool'];
}

class CellUpdateSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('sec_widgets', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->boolean('is_active')->default(false);
            $table->boolean('is_admin')->default(false);
        });
    }

    /**
     * Token containing the model + only the editable columns (is_active).
     */
    private function token(): string
    {
        return Table::make(SecWidget::query())
            ->columns([
                TextColumn::make('name'),
                ToggleColumn::make('is_active'),
            ])
            ->toData()
            ->model;
    }

    public function test_an_editable_column_can_be_updated(): void
    {
        $widget = SecWidget::create(['name' => 'A', 'is_active' => false]);

        $response = $this->postJson(route('kinetix.tables.cell-update'), [
            'model'    => $this->token(),
            'recordId' => $widget->id,
            'column'   => 'is_active',
            'value'    => true,
        ]);

        $response->assertOk();
        $this->assertTrue($widget->fresh()->is_active);
    }

    public function test_a_non_editable_column_is_rejected(): void
    {
        $widget = SecWidget::create(['name' => 'A', 'is_admin' => false]);

        $response = $this->postJson(route('kinetix.tables.cell-update'), [
            'model'    => $this->token(),
            'recordId' => $widget->id,
            'column'   => 'is_admin', // not declared as an editable column
            'value'    => true,
        ]);

        $response->assertForbidden();
        $this->assertFalse($widget->fresh()->is_admin);
    }

    public function test_a_display_only_column_is_rejected(): void
    {
        $widget = SecWidget::create(['name' => 'A']);

        $response = $this->postJson(route('kinetix.tables.cell-update'), [
            'model'    => $this->token(),
            'recordId' => $widget->id,
            'column'   => 'name', // a TextColumn — display only
            'value'    => 'HACKED',
        ]);

        $response->assertForbidden();
        $this->assertSame('A', $widget->fresh()->name);
    }

    public function test_a_tampered_token_is_rejected(): void
    {
        $widget = SecWidget::create(['name' => 'A']);

        $response = $this->postJson(route('kinetix.tables.cell-update'), [
            'model'    => 'not-a-valid-token',
            'recordId' => $widget->id,
            'column'   => 'is_active',
            'value'    => true,
        ]);

        $response->assertStatus(400);
    }
}
