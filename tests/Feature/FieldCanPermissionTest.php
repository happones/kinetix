<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Infolists\Components\TextEntry;
use Happones\Kinetix\Infolists\Infolist;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class CanFieldUser extends Authenticatable
{
    protected $table = 'can_field_users';

    public $timestamps = false;

    protected $guarded = [];
}

class CanFieldEmployee extends Model
{
    protected $table = 'can_field_employees';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * `->can('employees.viewSalary')` — the field-level permission gate. Checked
 * at serialization with NO subject (unlike `authorize()` it never defers), so
 * a denied field disappears from form schemas, validation rules, submitted
 * state, infolists AND table columns/rows: the gated data never leaves the
 * server, and can't be written back in either.
 */
class FieldCanPermissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('can_field_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        Schema::create('can_field_employees', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('salary')->nullable();
        });

        CanFieldEmployee::create(['name' => 'Ada', 'salary' => 90000]);

        Gate::define('employees.viewSalary', fn ($user): bool => $user->name === 'HR');
    }

    private function actingAsHr(): void
    {
        $this->actingAs(CanFieldUser::create(['name' => 'HR']));
    }

    private function actingAsViewer(): void
    {
        $this->actingAs(CanFieldUser::create(['name' => 'Viewer']));
    }

    private function employeeForm(): Form
    {
        return Form::make(CanFieldEmployee::first())
            ->operation('edit')
            ->schema([
                TextInput::make('name')->required(),
                TextInput::make('salary')->can('employees.viewSalary')->required(),
            ]);
    }

    public function test_a_denied_field_is_stripped_from_the_form_schema(): void
    {
        $this->actingAsViewer();
        $fields = array_column($this->employeeForm()->toArray()['schema'], 'name');
        $this->assertSame(['name'], $fields);

        $this->actingAsHr();
        $fields = array_column($this->employeeForm()->toArray()['schema'], 'name');
        $this->assertSame(['name', 'salary'], $fields);
    }

    public function test_a_denied_field_is_stripped_from_rules_and_state(): void
    {
        $this->actingAsViewer();
        $form = $this->employeeForm();

        // The required rule of the denied field must not block submission…
        $this->assertSame(['name' => ['required']], $form->getValidationRules());

        // …and a smuggled value for it never reaches the model.
        $state = $form->getState(['name' => 'Ada Updated', 'salary' => 1]);
        $this->assertSame(['name' => 'Ada Updated'], $state);
    }

    public function test_an_allowed_user_keeps_the_field_in_rules_and_state(): void
    {
        $this->actingAsHr();
        $form = $this->employeeForm();

        $this->assertArrayHasKey('salary', $form->getValidationRules());
        $this->assertSame(
            ['name' => 'Ada', 'salary' => 95000],
            $form->getState(['name' => 'Ada', 'salary' => 95000]),
        );
    }

    public function test_a_denied_entry_is_stripped_from_the_infolist(): void
    {
        $employee = CanFieldEmployee::first();
        $infolist = fn (): array => Infolist::make($employee)->schema([
            TextEntry::make('name'),
            TextEntry::make('salary')->can('employees.viewSalary'),
        ])->toArray();

        $this->actingAsViewer();
        $this->assertSame(['name'], array_column($infolist()['schema'], 'name'));

        $this->actingAsHr();
        $this->assertSame(['name', 'salary'], array_column($infolist()['schema'], 'name'));
    }

    public function test_a_denied_column_is_stripped_from_table_headers_and_rows(): void
    {
        $table = fn (): array => Table::make(CanFieldEmployee::query())
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('salary')->can('employees.viewSalary'),
            ])
            ->toArray();

        $this->actingAsViewer();
        $data = $table();
        $this->assertSame(['name'], array_column($data['columns'], 'name'));
        // The salary VALUE is absent from every row payload too.
        $this->assertArrayNotHasKey('salary', $data['records'][0]['values']);

        $this->actingAsHr();
        $data = $table();
        $this->assertSame(['name', 'salary'], array_column($data['columns'], 'name'));
        $this->assertSame(90000, $data['records'][0]['values']['salary']);
    }

    /**
     * A column gated with a policy-ability STRING and no explicit subject must
     * be evaluated against the user at serialization — not deferred like an
     * action awaiting its record. A column has no per-row authorization pass,
     * so deferring would mean the gate never runs and the column always leaks.
     */
    public function test_a_column_authorized_by_a_policy_ability_is_gated(): void
    {
        $table = fn (): array => Table::make(CanFieldEmployee::query())
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('salary')->authorize('employees.viewSalary'),
            ])
            ->toArray();

        $this->actingAsViewer();
        $data = $table();
        $this->assertSame(['name'], array_column($data['columns'], 'name'));
        $this->assertArrayNotHasKey('salary', $data['records'][0]['values']);

        $this->actingAsHr();
        $data = $table();
        $this->assertSame(['name', 'salary'], array_column($data['columns'], 'name'));
        $this->assertSame(90000, $data['records'][0]['values']['salary']);
    }

    /**
     * `visible(false)` and a record-independent `visible(fn)` both drop the
     * whole column (header + every cell value).
     */
    public function test_a_hidden_column_is_stripped(): void
    {
        $table = fn (): array => Table::make(CanFieldEmployee::query())
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('salary')->visible(false),
            ])
            ->toArray();

        $this->actingAsViewer();
        $data = $table();
        $this->assertSame(['name'], array_column($data['columns'], 'name'));
        $this->assertArrayNotHasKey('salary', $data['records'][0]['values']);
    }

    /**
     * A column has no per-record pass to defer a closure to, so it runs with
     * no record: `fn ()` and an untyped `fn ($record)` decide now, and one
     * typed for a record the column never has fails closed.
     */
    public function test_a_column_visibility_closure_runs_without_a_record(): void
    {
        $this->actingAsViewer();

        $data = Table::make(CanFieldEmployee::query())
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('salary')->visible(fn (): bool => false),
                TextColumn::make('bonus')->hidden(fn ($record): bool => true),
                TextColumn::make('typed')->visible(fn (CanFieldEmployee $record): bool => true),
                TextColumn::make('email')->visible(fn ($record): bool => $record === null),
            ])
            ->toArray();

        $this->assertSame(['name', 'email'], array_column($data['columns'], 'name'));
        $this->assertArrayNotHasKey('salary', $data['records'][0]['values']);
    }
}
