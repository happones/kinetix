<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Actions\ActionGroup;
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
use Illuminate\Testing\TestResponse;

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

    private function runForm(string $descriptor, array $payload = []): TestResponse
    {
        return $this->from('/x')->post(route('kinetix.tables.form-action'), [
            'descriptor' => $descriptor,
            'action'     => 'rename-widget',
            'data'       => ['name' => 'Hacked'],
            ...$payload,
        ]);
    }

    public function test_a_record_action_denied_by_its_own_ability_cannot_be_invoked(): void
    {
        FormWidgetRecord::create(['name' => 'Mine']);
        FormWidgetRecord::create(['name' => 'Theirs']);
        Gate::define('rename-widget', fn ($user, FormWidgetRecord $record): bool => $record->name === 'Mine');
        $this->actingAs(FormActionUser::create(['name' => 'Bob']));

        // Row 2 hides the action; before 0.207.1 a crafted POST with the
        // table-wide descriptor still ran it there.
        $descriptor = $this->descriptorFor(
            Table::make(FormWidgetRecord::query())
                ->recordActions([RenameWidget::make()->authorize('rename-widget')]),
        );

        $this->runForm($descriptor, ['recordId' => 2])->assertForbidden();
        $this->assertSame('Theirs', FormWidgetRecord::find(2)->name);

        $this->runForm($descriptor, ['recordId' => 1])->assertRedirect('/x');
        $this->assertSame('Hacked', FormWidgetRecord::find(1)->name);
    }

    public function test_a_record_action_only_runs_on_rows_it_rendered_for(): void
    {
        FormWidgetRecord::create(['name' => 'Editable']);
        FormWidgetRecord::create(['name' => 'Locked']);

        $descriptor = $this->descriptorFor(
            Table::make(FormWidgetRecord::query())
                ->recordActions([
                    RenameWidget::make()->visible(fn (FormWidgetRecord $record): bool => $record->name !== 'Locked'),
                ]),
        );

        $this->runForm($descriptor, ['recordId' => 2])->assertForbidden();
        $this->assertSame('Locked', FormWidgetRecord::find(2)->name);

        $this->runForm($descriptor, ['recordId' => 1])->assertRedirect('/x');
        $this->assertSame('Hacked', FormWidgetRecord::find(1)->name);
    }

    public function test_a_record_action_inside_a_denied_group_cannot_be_invoked(): void
    {
        FormWidgetRecord::create(['name' => 'Old']);

        $data = Table::make(FormWidgetRecord::query())
            ->recordActions([ActionGroup::make([RenameWidget::make()])->authorize(false)])
            ->toData();

        $this->assertNull($data->formActionDescriptor);
    }

    public function test_a_record_action_cannot_be_invoked_without_its_record(): void
    {
        FormWidgetRecord::create(['name' => 'Old']);
        Gate::define('rename-widget-ability', fn ($user, $record): bool => false);
        $this->actingAs(FormActionUser::create(['name' => 'Bob']));

        $descriptor = $this->descriptorFor(
            Table::make(FormWidgetRecord::query())
                ->writeAbility('rename-widget-ability')
                ->recordActions([RenameWidget::make()]),
        );

        // No recordId used to run handle($data, null) with no policy check.
        $this->runForm($descriptor)->assertForbidden();
        // A malformed id is not a reason to run it record-less either.
        $this->runForm($descriptor, ['recordId' => [1]])->assertStatus(400);
    }

    public function test_a_toolbar_action_cannot_be_invoked_with_a_record(): void
    {
        FormWidgetRecord::create(['name' => 'Old']);

        $descriptor = $this->descriptorFor(
            Table::make(FormWidgetRecord::query())
                ->toolbarActions([RenameWidget::make()]),
        );

        $this->runForm($descriptor, ['recordId' => 1])->assertForbidden();
        $this->assertSame('Old', FormWidgetRecord::find(1)->name);
    }

    public function test_a_toolbar_actions_own_ability_is_checked_against_the_model(): void
    {
        $this->actingAs(FormActionUser::create(['name' => 'Bob']));
        Gate::define('rename-widgets', fn ($user, string $model): bool => $model === FormWidgetRecord::class && $user->name === 'Ann');

        $descriptor = $this->descriptorFor(
            Table::make(FormWidgetRecord::query())
                ->toolbarActions([RenameWidget::make()->authorize('rename-widgets')]),
        );

        $this->runForm($descriptor)->assertForbidden();
    }

    public function test_a_toolbar_actions_visibility_closure_runs_without_a_record(): void
    {
        $data = Table::make(FormWidgetRecord::query())
            ->toolbarActions([RenameWidget::make()->visible(fn ($record): bool => false)])
            ->toData();

        $this->assertNull($data->formActionDescriptor);
        // The button agrees with the endpoint: it isn't shown either.
        $this->assertSame([], $data->toolbarActions);
    }

    public function test_a_toolbar_gate_that_needs_a_record_hides_the_action_without_failing(): void
    {
        $data = Table::make(FormWidgetRecord::query())
            ->toolbarActions([
                RenameWidget::make()->visible(fn ($record): bool => $record->name === 'x'),
                RenameWidget::make('rename-all'),
            ])
            ->toData();

        $this->assertSame(['rename-all'], array_map(static fn ($a) => $a->name, $data->toolbarActions));
    }

    /**
     * Each row used to carry its action's whole form — a relationship Select
     * ran its options query once per row. Rows now ship the action alone and
     * the modal fetches the form for its row.
     */
    public function test_a_row_action_ships_without_its_form_and_fetches_it_on_open(): void
    {
        FormWidgetRecord::create(['name' => 'Mine']);
        FormWidgetRecord::create(['name' => 'Theirs']);

        $data = Table::make(FormWidgetRecord::query())
            ->recordActions([RenameWidget::make()->visible(fn (FormWidgetRecord $record): bool => $record->name === 'Mine')])
            ->toolbarActions([RenameWidget::make('rename-all')])
            ->toData();

        $rowAction = $data->records[0]->actions[0];
        $this->assertNull($rowAction->form);
        $this->assertTrue($rowAction->formOnOpen);
        // The toolbar's single instance still ships its form.
        $this->assertNotNull($data->toolbarActions[0]->form);

        $fetch = fn (int $id) => $this->postJson(route('kinetix.tables.form-action.form'), [
            'descriptor' => $data->formActionDescriptor,
            'action'     => 'rename-widget',
            'recordId'   => $id,
        ]);

        $fetch(1)->assertOk()->assertJsonPath('form.schema.0.name', 'name');
        // The same checks as a submission: not on a row it didn't render on.
        $fetch(2)->assertForbidden();
        $fetch(99)->assertNotFound();
    }
}
