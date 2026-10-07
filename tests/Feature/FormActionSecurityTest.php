<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Actions\FormAction;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class FormActionUser extends Authenticatable
{
    protected $table = 'form_action_users';

    public $timestamps = false;

    protected $guarded = [];
}

class FormWidgetRecord extends Model
{
    protected $table = 'form_widget_records';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * A server-side form action: rename the record from a required `name` field.
 * Name defaults to the kebab of the class short name ('rename-widget').
 */
class RenameWidget extends FormAction
{
    protected function form(Form $form, ?Model $record = null): Form
    {
        return $form->schema([
            TextInput::make('name')->required(),
        ]);
    }

    public function handle(array $data, ?Model $record): void
    {
        if ($record !== null) {
            $record->name = $data['name'];
            $record->save();
        }
    }
}

class FormActionSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The test harness runs Kinetix routes with no middleware, so no session
        // is started by default. Validation errors from the form endpoint land
        // in the session (standard Laravel redirect-with-errors), so switch to
        // the array session driver — it needs no cookie-backed request — and
        // start it, letting assertSessionHasErrors read the error bag.
        config()->set('session.driver', 'array');
        $this->startSession();

        Schema::create('form_widget_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('team')->default('a');
        });

        Schema::create('form_action_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
    }

    private function descriptorFor(Table $table): string
    {
        return $table->toData()->formActionDescriptor ?? '';
    }

    public function test_a_registered_form_action_runs_with_validated_state(): void
    {
        FormWidgetRecord::create(['name' => 'Old']);

        $descriptor = $this->descriptorFor(
            Table::make(FormWidgetRecord::query())
                ->recordActions([RenameWidget::make()]),
        );

        $this->assertNotSame('', $descriptor);

        $response = $this->from('/x')->post(route('kinetix.tables.form-action'), [
            'descriptor' => $descriptor,
            'action'     => 'rename-widget',
            'recordId'   => 1,
            'data'       => ['name' => 'New'],
        ]);

        $response->assertRedirect('/x');
        $this->assertSame('New', FormWidgetRecord::find(1)->name);
    }

    public function test_a_toolbar_form_action_runs_without_a_record(): void
    {
        $descriptor = $this->descriptorFor(
            Table::make(FormWidgetRecord::query())
                ->toolbarActions([RenameWidget::make()]),
        );

        $this->assertNotSame('', $descriptor);

        // No recordId → the handler runs with a null record (no-op here) and
        // the request still succeeds: the toolbar path never resolves a row.
        $response = $this->from('/x')->post(route('kinetix.tables.form-action'), [
            'descriptor' => $descriptor,
            'action'     => 'rename-widget',
            'data'       => ['name' => 'New'],
        ]);

        $response->assertRedirect('/x');
        $response->assertSessionHasNoErrors();
    }

    public function test_invalid_data_fails_validation(): void
    {
        FormWidgetRecord::create(['name' => 'Old']);

        $descriptor = $this->descriptorFor(
            Table::make(FormWidgetRecord::query())
                ->recordActions([RenameWidget::make()]),
        );

        // The required `name` is missing: validation fails and the record is
        // untouched (the handler never runs).
        $response = $this->from('/x')->post(route('kinetix.tables.form-action'), [
            'descriptor' => $descriptor,
            'action'     => 'rename-widget',
            'recordId'   => 1,
            'data'       => [],
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertSame('Old', FormWidgetRecord::find(1)->name);
    }

    public function test_an_action_name_not_in_the_descriptor_is_rejected(): void
    {
        FormWidgetRecord::create(['name' => 'Old']);

        $descriptor = $this->descriptorFor(
            Table::make(FormWidgetRecord::query())
                ->recordActions([RenameWidget::make()]),
        );

        $response = $this->post(route('kinetix.tables.form-action'), [
            'descriptor' => $descriptor,
            'action'     => 'delete-everything', // not registered
            'recordId'   => 1,
            'data'       => ['name' => 'New'],
        ]);

        $response->assertForbidden();
        $this->assertSame('Old', FormWidgetRecord::find(1)->name);
    }

    public function test_a_record_outside_the_table_scope_is_refused(): void
    {
        // Table scoped to team 'a'; record 2 is in team 'b'.
        FormWidgetRecord::create(['name' => 'A', 'team' => 'a']);
        FormWidgetRecord::create(['name' => 'B', 'team' => 'b']);

        $descriptor = $this->descriptorFor(
            Table::make(FormWidgetRecord::query()->where('team', 'a'))
                ->recordActions([RenameWidget::make()]),
        );

        $response = $this->post(route('kinetix.tables.form-action'), [
            'descriptor' => $descriptor,
            'action'     => 'rename-widget',
            'recordId'   => 2, // out of scope
            'data'       => ['name' => 'Hacked'],
        ]);

        $response->assertNotFound();
        $this->assertSame('B', FormWidgetRecord::find(2)->name);
    }

    public function test_the_record_is_authorized_by_the_policy(): void
    {
        FormWidgetRecord::create(['name' => 'Old']);

        // A policy ability the table enforces via writeAbility(); deny it.
        Gate::define('rename-widget-ability', fn ($user, $record): bool => false);

        // Act as a user BEFORE minting the descriptor so its user-binding
        // matches the request (SignedDescriptor binds to auth()->id()).
        $this->actingAs(FormActionUser::create(['name' => 'Bob']));

        $descriptor = $this->descriptorFor(
            Table::make(FormWidgetRecord::query())
                ->writeAbility('rename-widget-ability')
                ->recordActions([RenameWidget::make()]),
        );

        $response = $this->post(route('kinetix.tables.form-action'), [
            'descriptor' => $descriptor,
            'action'     => 'rename-widget',
            'recordId'   => 1,
            'data'       => ['name' => 'New'],
        ]);

        $response->assertForbidden();
        $this->assertSame('Old', FormWidgetRecord::find(1)->name);
    }

    public function test_a_tampered_descriptor_is_rejected(): void
    {
        FormWidgetRecord::create(['name' => 'Old']);

        $response = $this->post(route('kinetix.tables.form-action'), [
            'descriptor' => 'not-a-valid-token',
            'action'     => 'rename-widget',
            'recordId'   => 1,
            'data'       => ['name' => 'New'],
        ]);

        $response->assertStatus(400);
        $this->assertSame('Old', FormWidgetRecord::find(1)->name);
    }
}
