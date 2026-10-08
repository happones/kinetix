<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tables\Columns\NumberInputColumn;
use Happones\Kinetix\Tables\Columns\SelectColumn;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Columns\TextInputColumn;
use Happones\Kinetix\Tables\Columns\ToggleColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

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
            $table->string('role')->default('viewer');
            $table->integer('qty')->default(0);
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

    /**
     * A token whose editable columns carry server-side validation rules: a
     * Select constrained to its options, a Number bounded by min/max.
     */
    private function validatingToken(): string
    {
        return Table::make(SecWidget::query())
            ->columns([
                TextColumn::make('name'),
                ToggleColumn::make('is_active'),
                SelectColumn::make('role')
                    ->options(['viewer' => 'Viewer', 'editor' => 'Editor']),
                NumberInputColumn::make('qty')
                    ->min(0)->max(10),
            ])
            ->toData()
            ->model;
    }

    public function test_a_select_value_outside_its_options_is_rejected(): void
    {
        $widget = SecWidget::create(['name' => 'A', 'role' => 'viewer']);

        $response = $this->postJson(route('kinetix.tables.cell-update'), [
            'model'    => $this->validatingToken(),
            'recordId' => $widget->id,
            'column'   => 'role',
            'value'    => 'superadmin', // not one of the declared options
        ]);

        $response->assertStatus(422);
        $this->assertSame('viewer', $widget->fresh()->role);
    }

    public function test_a_valid_select_value_is_accepted(): void
    {
        $widget = SecWidget::create(['name' => 'A', 'role' => 'viewer']);

        $response = $this->postJson(route('kinetix.tables.cell-update'), [
            'model'    => $this->validatingToken(),
            'recordId' => $widget->id,
            'column'   => 'role',
            'value'    => 'editor',
        ]);

        $response->assertOk();
        $this->assertSame('editor', $widget->fresh()->role);
    }

    public function test_a_toggle_value_that_is_not_boolean_is_rejected(): void
    {
        $widget = SecWidget::create(['name' => 'A', 'is_active' => false]);

        $response = $this->postJson(route('kinetix.tables.cell-update'), [
            'model'    => $this->validatingToken(),
            'recordId' => $widget->id,
            'column'   => 'is_active',
            'value'    => 'not-a-bool',
        ]);

        $response->assertStatus(422);
        $this->assertFalse($widget->fresh()->is_active);
    }

    public function test_a_number_value_outside_its_bounds_is_rejected(): void
    {
        $widget = SecWidget::create(['name' => 'A', 'qty' => 5]);

        $response = $this->postJson(route('kinetix.tables.cell-update'), [
            'model'    => $this->validatingToken(),
            'recordId' => $widget->id,
            'column'   => 'qty',
            'value'    => 999, // exceeds max:10
        ]);

        $response->assertStatus(422);
        $this->assertSame(5, $widget->fresh()->qty);
    }

    /**
     * A column has no per-record pass: a record-independent visible()/hidden()
     * closure used to be deferred forever, so the column shipped and — when
     * editable — accepted writes from users the closure was meant to exclude.
     */
    public function test_a_column_hidden_by_a_closure_is_not_writable(): void
    {
        $widget = SecWidget::create(['name' => 'A']);

        $token = Table::make(SecWidget::query())
            ->columns([
                TextColumn::make('name'),
                SelectColumn::make('role')
                    ->options(['viewer' => 'Viewer', 'admin' => 'Admin'])
                    ->visible(fn (): bool => false),
            ])
            ->toData()
            ->model;

        $response = $this->postJson(route('kinetix.tables.cell-update'), [
            'model'    => $token,
            'recordId' => $widget->id,
            'column'   => 'role',
            'value'    => 'admin',
        ]);

        $response->assertForbidden();
        $this->assertSame('viewer', $widget->fresh()->role);
    }

    private function editName(Table $table, SecWidget $widget, mixed $value): TestResponse
    {
        return $this->postJson(route('kinetix.tables.cell-update'), [
            'model'    => $table->toData()->model,
            'recordId' => $widget->id,
            'column'   => 'name',
            'value'    => $value,
        ]);
    }

    /**
     * The value was validated under the key `value`, so `unique:sec_widgets`
     * looked for a `value` column; and the row being edited collided with
     * itself when its value didn't change.
     */
    public function test_a_unique_rule_checks_the_columns_own_attribute_and_ignores_its_row(): void
    {
        $a = SecWidget::create(['name' => 'Alpha']);
        SecWidget::create(['name' => 'Beta']);
        $table = fn (): Table => Table::make(SecWidget::query())
            ->columns([TextInputColumn::make('name')->rules(['unique:sec_widgets'])]);

        $this->editName($table(), $a, 'Alpha')->assertOk();

        $response = $this->editName($table(), $a, 'Beta');
        $response->assertStatus(422);
        $this->assertStringContainsString('name', (string) $response->json('message'));
        $this->assertSame('Alpha', $a->fresh()->name);
    }

    public function test_a_unique_rule_object_also_ignores_its_row(): void
    {
        $a = SecWidget::create(['name' => 'Alpha']);

        $table = Table::make(SecWidget::query())
            ->columns([TextInputColumn::make('name')->rules([Rule::unique('sec_widgets', 'name')])]);

        $this->editName($table, $a, 'Alpha')->assertOk();
    }

    public function test_a_closure_rule_is_refused_when_declared(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Column [name]');

        TextInputColumn::make('name')->rules([fn ($attribute, $value, $fail) => null]);
    }

    public function test_a_rule_holding_a_closure_is_refused_when_declared(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TextInputColumn::make('name')->rules([Rule::unique('sec_widgets')->where(fn ($q) => $q)]);
    }

    /**
     * A select whose options all filtered out for this user wrote anything.
     */
    public function test_a_select_with_no_options_accepts_no_value(): void
    {
        $widget = SecWidget::create(['name' => 'A', 'role' => 'viewer']);

        $token = Table::make(SecWidget::query())
            ->columns([SelectColumn::make('role')->options(fn (): array => [])])
            ->toData()
            ->model;

        $this->postJson(route('kinetix.tables.cell-update'), [
            'model'    => $token,
            'recordId' => $widget->id,
            'column'   => 'role',
            'value'    => 'superadmin',
        ])->assertStatus(422);

        $this->assertSame('viewer', $widget->fresh()->role);
    }
}
