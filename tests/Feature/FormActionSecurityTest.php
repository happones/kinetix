<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Actions\ActionGroup;
use Happones\Kinetix\Actions\FormAction;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use LogicException;
use Mockery;
use RuntimeException;

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

/**
 * A toolbar form action that creates a widget, its name prefixed by what the
 * table configured.
 */
class CreateWidget extends FormAction
{
    protected string $prefix = '';

    public function prefix(string $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    protected function form(Form $form, ?Model $record = null): Form
    {
        return $form->schema([
            TextInput::make('name')->required()->default($this->prefix),
        ]);
    }

    public function handle(array $data, ?Model $record): void
    {
        FormWidgetRecord::create(['name' => $this->prefix.$data['name']]);
    }
}

class FormWidgetRenamePolicy
{
    public function rename(FormActionUser $user, FormWidgetRecord $record): bool
    {
        return true;
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

        $table = fn (): Table => Table::make(FormWidgetRecord::query())
            ->toolbarActions([RenameWidget::make()->authorize('rename-widgets')]);

        // The button is judged as the endpoint will judge it, against the
        // class: Bob neither sees it nor gets it sealed.
        $data = $table()->toData();
        $this->assertSame([], $data->toolbarActions);
        $this->assertNull($data->formActionDescriptor);

        // And the endpoint checks it again: an ability revoked after the
        // page rendered refuses the run.
        Gate::define('rename-widgets', fn ($user, string $model): bool => true);
        $descriptor = $this->descriptorFor($table());
        $this->assertNotSame('', $descriptor);

        Gate::define('rename-widgets', fn ($user, string $model): bool => false);
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

    private function runToolbarForm(string $descriptor, string $action = 'create-widget'): TestResponse
    {
        return $this->from('/x')->post(route('kinetix.tables.form-action'), [
            'descriptor' => $descriptor,
            'action'     => $action,
            'data'       => ['name' => 'Ada'],
        ]);
    }

    public function test_fluent_configuration_reaches_the_endpoints_form_and_handler(): void
    {
        $descriptor = $this->descriptorFor(
            Table::make(FormWidgetRecord::query())
                ->toolbarActions([CreateWidget::make()->prefix('Dr. ')]),
        );

        $this->runToolbarForm($descriptor)->assertRedirect('/x');

        $this->assertSame(['Dr. Ada'], FormWidgetRecord::query()->pluck('name')->all());
    }

    /**
     * A toolbar ActionGroup judged its children as a record-less template
     * pass does — deferring what needs a record — so the button showed while
     * the action was never sealed.
     */
    public function test_a_toolbar_groups_children_are_judged_like_the_endpoint_judges_them(): void
    {
        $data = Table::make(FormWidgetRecord::query())
            ->toolbarActions([
                ActionGroup::make([
                    RenameWidget::make()->visible(fn (FormWidgetRecord $record): bool => true),
                    CreateWidget::make(),
                ])->label('More'),
                ActionGroup::make([
                    RenameWidget::make('rename-again')->visible(fn ($record): bool => $record->name === 'x'),
                ])->label('Empty'),
            ])
            ->toData();

        // The group keeps only the child the endpoint would run, and a group
        // left with none doesn't render.
        $this->assertCount(1, $data->toolbarActions);
        $this->assertSame(['create-widget'], array_map(static fn ($a) => $a->name, $data->toolbarActions[0]->actions));

        $payload = Crypt::decrypt($data->formActionDescriptor);
        $this->assertSame(['create-widget'], array_keys($payload['forms']['toolbar']));
    }

    public function test_a_toolbar_child_in_a_hidden_group_is_not_sealed(): void
    {
        $data = Table::make(FormWidgetRecord::query())
            ->toolbarActions([ActionGroup::make([CreateWidget::make()])->visible(false)])
            ->toData();

        $this->assertSame([], $data->toolbarActions);
        $this->assertNull($data->formActionDescriptor);
    }

    public function test_a_toolbar_ability_whose_policy_needs_a_record_hides_the_action_instead_of_failing(): void
    {
        $this->actingAs(FormActionUser::create(['name' => 'Ann']));
        Gate::policy(FormWidgetRecord::class, FormWidgetRenamePolicy::class);

        $data = Table::make(FormWidgetRecord::query())
            ->toolbarActions([CreateWidget::make()->authorize('rename')])
            ->toData();

        $this->assertSame([], $data->toolbarActions);
        $this->assertNull($data->formActionDescriptor);
    }

    /**
     * Laravel only strips a class-name argument for policies: a Gate::define()
     * closure typed for a model got `FormWidgetRecord::class` and threw a
     * TypeError, taking the whole table down.
     */
    public function test_a_toolbar_ability_defined_for_an_instance_hides_the_action_instead_of_failing(): void
    {
        $this->actingAs(FormActionUser::create(['name' => 'Ann']));
        Gate::define('rename-one', fn ($user, FormWidgetRecord $record): bool => true);

        $data = Table::make(FormWidgetRecord::query())
            ->toolbarActions([CreateWidget::make()->authorize('rename-one')])
            ->toData();

        $this->assertSame([], $data->toolbarActions);
        $this->assertNull($data->formActionDescriptor);
    }

    public function test_a_toolbar_authorize_closure_that_needs_a_record_hides_the_action(): void
    {
        $data = Table::make(FormWidgetRecord::query())
            ->toolbarActions([
                CreateWidget::make()->authorize(fn (FormWidgetRecord $record): bool => true),
                CreateWidget::make('create-other')->authorize(fn (): bool => true),
            ])
            ->toData();

        $this->assertSame(['create-other'], array_map(static fn ($a) => $a->name, $data->toolbarActions));
    }

    public function test_a_footer_form_action_is_sealed_and_runs_like_a_toolbar_one(): void
    {
        $data = Table::make(FormWidgetRecord::query())
            ->footerActions([CreateWidget::make()->prefix('F-')])
            ->toData();

        $this->assertSame(['create-widget'], array_map(static fn ($a) => $a->name, $data->footerActions));

        $this->runToolbarForm((string) $data->formActionDescriptor)->assertRedirect('/x');
        $this->assertSame(['F-Ada'], FormWidgetRecord::query()->pluck('name')->all());
    }

    public function test_the_same_form_action_in_the_toolbar_and_the_footer_is_one_action(): void
    {
        $data = Table::make(FormWidgetRecord::query())
            ->toolbarActions([CreateWidget::make()->prefix('X-')])
            ->footerActions([CreateWidget::make()->prefix('X-')])
            ->toData();

        $this->assertSame(['create-widget'], array_keys(Crypt::decrypt($data->formActionDescriptor)['forms']['toolbar']));

        $this->expectException(LogicException::class);

        Table::make(FormWidgetRecord::query())
            ->toolbarActions([CreateWidget::make()->prefix('X-')])
            ->footerActions([CreateWidget::make()->prefix('Y-')])
            ->toData();
    }

    /**
     * Empty-state actions run with no record, like the toolbar's: they were
     * judged with the ability deferred and never sealed, so a denied one
     * showed and an allowed one did nothing.
     */
    public function test_an_empty_state_form_action_is_judged_and_sealed_like_a_toolbar_one(): void
    {
        $this->actingAs(FormActionUser::create(['name' => 'Ann']));
        Gate::define('import-widgets', fn ($user): bool => false);

        $data = Table::make(FormWidgetRecord::query())
            ->emptyStateActions([
                CreateWidget::make()->prefix('E-'),
                CreateWidget::make('import')->authorize('import-widgets'),
            ])
            ->toData();

        $this->assertSame(['create-widget'], array_map(static fn ($a) => $a->name, $data->emptyState->actions));

        $this->runToolbarForm((string) $data->formActionDescriptor)->assertRedirect('/x');
        $this->assertSame(['E-Ada'], FormWidgetRecord::query()->pluck('name')->all());
    }

    /**
     * `fn ($record) => $record->…` in a toolbar is expected to fail without a
     * record; it was reported on every render.
     */
    public function test_a_toolbar_gate_using_the_missing_record_is_not_reported(): void
    {
        $handler = $this->spy(ExceptionHandler::class);

        $data = Table::make(FormWidgetRecord::query())
            ->toolbarActions([
                CreateWidget::make()->visible(fn ($record): bool => $record->name === 'x'),
                CreateWidget::make('draft')->visible(fn ($record): bool => $record->isDraft()),
                CreateWidget::make('broken')->visible(fn ($record = null): bool => throw new RuntimeException('Gate failed.')),
            ])
            ->toData();

        $this->assertSame([], $data->toolbarActions);

        // A gate failing for another reason still is.
        $reported = [];
        $handler->shouldHaveReceived('report')->with(Mockery::on(static function (mixed $e) use (&$reported): bool {
            $reported[] = $e::class;

            return true;
        }));
        $this->assertSame([RuntimeException::class], array_values(array_unique($reported)));
    }
}
