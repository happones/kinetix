<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Actions\ActionGroup;
use Happones\Kinetix\Actions\AssociateAction;
use Happones\Kinetix\Actions\CreateAction;
use Happones\Kinetix\Actions\DeleteAction;
use Happones\Kinetix\Actions\DissociateAction;
use Happones\Kinetix\Actions\EditAction;
use Happones\Kinetix\Actions\ViewAction;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Infolists\Components\TextEntry;
use Happones\Kinetix\Infolists\Infolist;
use Happones\Kinetix\Resources\RelationManager;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class RmcProject extends Model
{
    protected $table = 'rmc_projects';

    public $timestamps = false;

    protected $guarded = [];

    public function tasks()
    {
        return $this->hasMany(RmcTask::class, 'project_id');
    }

    public function tags()
    {
        return $this->belongsToMany(RmcTag::class, 'rmc_project_tag', 'project_id', 'tag_id')
            ->withPivot('role');
    }
}

class RmcTask extends Model
{
    protected $table = 'rmc_tasks';

    public $timestamps = false;

    protected $guarded = [];
}

class RmcTag extends Model
{
    protected $table = 'rmc_tags';

    public $timestamps = false;

    protected $guarded = [];
}

class RmcUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class RmcTaskPolicy
{
    public function view(RmcUser $user, RmcTask $task): bool
    {
        return true;
    }

    public function create(RmcUser $user): bool
    {
        return (bool) $user->can_edit;
    }

    public function update(RmcUser $user, RmcTask $task): bool
    {
        return (bool) $user->can_edit;
    }

    public function delete(RmcUser $user, RmcTask $task): bool
    {
        return (bool) $user->can_edit;
    }
}

class TasksManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $recordTitleAttribute = 'title';

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('title')->required(),
        ]);
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('title'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('title')])
            ->toolbarActions([
                CreateAction::make()->modal('create'),
                AssociateAction::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()->modal('view'),
                    EditAction::make()->modal('edit'),
                    DeleteAction::make()->modal('delete'),
                    DissociateAction::make(),
                ]),
            ]);
    }
}

class TagsCrudManager extends RelationManager
{
    protected static string $relationship = 'tags';

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('name')])
            ->toolbarActions([CreateAction::make()->modal('create')])
            ->recordActions([DeleteAction::make()->modal('delete')]);
    }
}

class PivotTagsCrudManager extends RelationManager
{
    protected static string $relationship = 'tags';

    public function form(Form $form): Form
    {
        // `role` matches a withPivot() column: it fills from and writes to the
        // pivot row; `name` stays on the related model.
        return $form->schema([
            TextInput::make('name')->required(),
            TextInput::make('role'),
        ]);
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('name'),
            TextEntry::make('pivot.role'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('name')])
            ->toolbarActions([CreateAction::make()->modal('create')])
            ->recordActions([
                ViewAction::make()->modal('view'),
                EditAction::make()->modal('edit'),
            ]);
    }
}

class FormlessTasksManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('title')])
            ->toolbarActions([CreateAction::make()->modal('create')]);
    }
}

class AssociateOnTagsManager extends RelationManager
{
    protected static string $relationship = 'tags';

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('name')])
            ->toolbarActions([AssociateAction::make()]);
    }
}

class RelationRecordCrudTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('rmc_projects', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('rmc_tasks', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('project_id')->nullable();
            $table->string('title');
        });

        Schema::create('rmc_tags', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('rmc_project_tag', function (Blueprint $table) {
            $table->unsignedInteger('project_id');
            $table->unsignedInteger('tag_id');
            $table->string('role')->nullable();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->boolean('can_edit')->default(true);
        });
    }

    /**
     * @param class-string<RelationManager> $manager
     */
    private function descriptorFor(string $manager, RmcProject $project, RmcUser $user): string
    {
        $this->actingAs($user);

        return (string) $manager::make($project)->toData()->table->recordModals?->token
            ?: (string) $manager::make($project)->toData()->descriptor;
    }

    public function test_modal_actions_ship_relation_scoped_record_modals(): void
    {
        $user    = RmcUser::create([]);
        $project = RmcProject::create(['name' => 'Kinetix']);

        $this->actingAs($user);
        $data   = TasksManager::make($project)->toData();
        $modals = $data->table->recordModals;

        $this->assertNotNull($modals);
        $this->assertTrue($modals->enabled);
        $this->assertSame('relation', $modals->scope);
        $this->assertTrue($modals->hasForm);
        $this->assertTrue($modals->hasInfolist);
        $this->assertNotNull($modals->createForm);
        $this->assertNotNull($data->descriptor);

        // Associate/Dissociate are wired to this manager's browser events.
        $toolbar = collect($data->table->toolbarActions)->firstWhere('name', 'associate');
        $this->assertSame('open-associate', $toolbar->dispatchEvent);
        $this->assertSame('tasks', $toolbar->dispatchData['relationship'] ?? null);
    }

    public function test_record_endpoint_resolves_create_edit_and_view_through_the_manager(): void
    {
        $user    = RmcUser::create([]);
        $project = RmcProject::create(['name' => 'Kinetix']);
        $task    = $project->tasks()->create(['title' => 'Ship it']);

        $descriptor = $this->descriptorFor(TasksManager::class, $project, $user);

        $create = $this->postJson(route('kinetix.relations.record.resolve'), [
            'token' => $descriptor,
            'mode'  => 'create',
        ])->assertOk()->json('form');
        $this->assertNotNull($create);

        $edit = $this->postJson(route('kinetix.relations.record.resolve'), [
            'token' => $descriptor,
            'mode'  => 'edit',
            'id'    => $task->id,
        ])->assertOk()->json('form');
        $this->assertSame('Ship it', $edit['data']['title'] ?? null);

        $this->postJson(route('kinetix.relations.record.resolve'), [
            'token' => $descriptor,
            'mode'  => 'view',
            'id'    => $task->id,
        ])->assertOk()->assertJsonStructure(['infolist']);
    }

    public function test_store_creates_through_the_relationship_and_stamps_the_parent(): void
    {
        $user    = RmcUser::create([]);
        $project = RmcProject::create(['name' => 'Kinetix']);

        $this->from('/projects/1/edit')->post(route('kinetix.relations.record.store'), [
            'token' => $this->descriptorFor(TasksManager::class, $project, $user),
            // A forged project_id must be ignored — the relation stamps it.
            'data' => ['title' => 'New task', 'project_id' => 999],
        ])->assertRedirect('/projects/1/edit');

        $task = RmcTask::sole();
        $this->assertSame('New task', $task->title);
        $this->assertSame($project->id, (int) $task->project_id);
    }

    public function test_store_on_a_belongs_to_many_creates_and_attaches(): void
    {
        $user    = RmcUser::create([]);
        $project = RmcProject::create(['name' => 'Kinetix']);

        $this->from('/x')->post(route('kinetix.relations.record.store'), [
            'token' => $this->descriptorFor(TagsCrudManager::class, $project, $user),
            'data'  => ['name' => 'php'],
        ])->assertRedirect('/x');

        $this->assertSame(['php'], $project->tags()->pluck('name')->all());
    }

    public function test_update_and_delete_resolve_through_the_relationship_only(): void
    {
        $user    = RmcUser::create([]);
        $mine    = RmcProject::create(['name' => 'Mine']);
        $other   = RmcProject::create(['name' => 'Other']);
        $task    = $mine->tasks()->create(['title' => 'Ship it']);
        $foreign = $other->tasks()->create(['title' => 'Not yours']);

        $descriptor = $this->descriptorFor(TasksManager::class, $mine, $user);

        $this->from('/x')->put(route('kinetix.relations.record.update'), [
            'token' => $descriptor,
            'id'    => $task->id,
            'data'  => ['title' => 'Shipped'],
        ])->assertRedirect('/x');
        $this->assertSame('Shipped', $task->fresh()->title);

        // Another parent's child must 404, exactly like the table itself.
        $this->putJson(route('kinetix.relations.record.update'), [
            'token' => $descriptor,
            'id'    => $foreign->id,
            'data'  => ['title' => 'Hijacked'],
        ])->assertNotFound();
        $this->assertSame('Not yours', $foreign->fresh()->title);

        $this->from('/x')->delete(route('kinetix.relations.record.destroy'), [
            'token' => $descriptor,
            'id'    => $task->id,
        ])->assertRedirect('/x');
        $this->assertNull($task->fresh());
    }

    public function test_deleting_a_belongs_to_many_record_drops_the_pivot_row(): void
    {
        $user    = RmcUser::create([]);
        $project = RmcProject::create(['name' => 'Kinetix']);
        $tag     = RmcTag::create(['name' => 'php']);
        $project->tags()->attach($tag->id);

        $this->from('/x')->delete(route('kinetix.relations.record.destroy'), [
            'token' => $this->descriptorFor(TagsCrudManager::class, $project, $user),
            'id'    => $tag->id,
        ])->assertRedirect('/x');

        $this->assertNull($tag->fresh());
        $this->assertSame(0, $project->tags()->count());
    }

    public function test_the_child_models_policy_gates_record_writes(): void
    {
        Gate::policy(RmcTask::class, RmcTaskPolicy::class);

        $user    = RmcUser::create(['can_edit' => false]);
        $project = RmcProject::create(['name' => 'Kinetix']);
        $task    = $project->tasks()->create(['title' => 'Ship it']);

        $descriptor = $this->descriptorFor(TasksManager::class, $project, $user);

        $this->postJson(route('kinetix.relations.record.store'), [
            'token' => $descriptor,
            'data'  => ['title' => 'Nope'],
        ])->assertForbidden();

        $this->putJson(route('kinetix.relations.record.update'), [
            'token' => $descriptor,
            'id'    => $task->id,
            'data'  => ['title' => 'Nope'],
        ])->assertForbidden();

        $this->deleteJson(route('kinetix.relations.record.destroy'), [
            'token' => $descriptor,
            'id'    => $task->id,
        ])->assertForbidden();
    }

    public function test_associable_lists_orphans_and_associate_reparents(): void
    {
        $user    = RmcUser::create([]);
        $project = RmcProject::create(['name' => 'Kinetix']);
        $orphan  = RmcTask::create(['title' => 'Orphan', 'project_id' => null]);
        RmcTask::create(['title' => 'Owned', 'project_id' => RmcProject::create(['name' => 'Other'])->id]);

        $descriptor = $this->descriptorFor(TasksManager::class, $project, $user);

        $labels = collect(
            $this->postJson(route('kinetix.relations.associable'), [
                'descriptor' => $descriptor,
            ])->assertOk()->json('options'),
        )->pluck('label')->all();

        // Only records not owned by ANY parent are associable by default.
        $this->assertSame(['Orphan'], $labels);

        $this->postJson(route('kinetix.relations.associate'), [
            'descriptor' => $descriptor,
            'ids'        => [$orphan->id],
        ])->assertOk()->assertJson(['associated' => 1]);

        $this->assertSame($project->id, (int) $orphan->fresh()->project_id);
    }

    public function test_dissociate_nulls_the_foreign_key_for_this_parents_records_only(): void
    {
        $user    = RmcUser::create([]);
        $mine    = RmcProject::create(['name' => 'Mine']);
        $other   = RmcProject::create(['name' => 'Other']);
        $task    = $mine->tasks()->create(['title' => 'Ship it']);
        $foreign = $other->tasks()->create(['title' => 'Not yours']);

        $descriptor = $this->descriptorFor(TasksManager::class, $mine, $user);

        $this->postJson(route('kinetix.relations.dissociate'), [
            'descriptor' => $descriptor,
            'ids'        => [$task->id, $foreign->id],
        ])->assertOk();

        $this->assertNull($task->fresh()->project_id);
        // The other parent's record is untouched (relation-scoped lookup).
        $this->assertSame($other->id, (int) $foreign->fresh()->project_id);
        $this->assertNotNull($task->fresh());
    }

    public function test_edit_form_fills_pivot_columns_from_the_pivot_row(): void
    {
        $user    = RmcUser::create([]);
        $project = RmcProject::create(['name' => 'Kinetix']);
        $tag     = RmcTag::create(['name' => 'php']);
        $project->tags()->attach($tag->id, ['role' => 'writer']);

        $descriptor = $this->descriptorFor(PivotTagsCrudManager::class, $project, $user);

        $form = $this->postJson(route('kinetix.relations.record.resolve'), [
            'token' => $descriptor,
            'mode'  => 'edit',
            'id'    => $tag->id,
        ])->assertOk()->json('form');

        $this->assertSame('php', $form['data']['name'] ?? null);
        $this->assertSame('writer', $form['data']['role'] ?? null);
    }

    public function test_update_splits_pivot_state_between_record_and_pivot_row(): void
    {
        $user    = RmcUser::create([]);
        $project = RmcProject::create(['name' => 'Kinetix']);
        $tag     = RmcTag::create(['name' => 'php']);
        $project->tags()->attach($tag->id, ['role' => 'writer']);

        $descriptor = $this->descriptorFor(PivotTagsCrudManager::class, $project, $user);

        $this->put(route('kinetix.relations.record.update'), [
            'token' => $descriptor,
            'id'    => $tag->id,
            'data'  => ['name' => 'php8', 'role' => 'admin'],
        ])->assertRedirect();

        // `name` landed on the related model; `role` on the pivot row — a
        // `role` column doesn't even exist on rmc_tags, so a leak would 500.
        $this->assertSame('php8', $tag->fresh()->name);
        $this->assertSame('admin', $project->tags()->first()->pivot->role);
    }

    public function test_store_on_a_belongs_to_many_writes_pivot_fields_to_the_pivot_row(): void
    {
        $user    = RmcUser::create([]);
        $project = RmcProject::create(['name' => 'Kinetix']);

        $descriptor = $this->descriptorFor(PivotTagsCrudManager::class, $project, $user);

        $this->post(route('kinetix.relations.record.store'), [
            'token' => $descriptor,
            'data'  => ['name' => 'vue', 'role' => 'reviewer'],
        ])->assertRedirect();

        $attached = $project->tags()->first();
        $this->assertSame('vue', $attached->name);
        $this->assertSame('reviewer', $attached->pivot->role);
    }

    public function test_view_infolist_resolves_pivot_entries(): void
    {
        $user    = RmcUser::create([]);
        $project = RmcProject::create(['name' => 'Kinetix']);
        $tag     = RmcTag::create(['name' => 'php']);
        $project->tags()->attach($tag->id, ['role' => 'writer']);

        $descriptor = $this->descriptorFor(PivotTagsCrudManager::class, $project, $user);

        $infolist = $this->postJson(route('kinetix.relations.record.resolve'), [
            'token' => $descriptor,
            'mode'  => 'view',
            'id'    => $tag->id,
        ])->assertOk()->json('infolist');

        $this->assertStringContainsString('writer', json_encode($infolist));
    }

    public function test_modal_actions_without_a_form_throw_at_serialize_time(): void
    {
        $project = RmcProject::create(['name' => 'Kinetix']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('declares no form()');

        FormlessTasksManager::make($project)->toData();
    }

    public function test_associate_on_a_belongs_to_many_relation_throws(): void
    {
        $project = RmcProject::create(['name' => 'Kinetix']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('require a HasMany/MorphMany relation');

        AssociateOnTagsManager::make($project)->toData();
    }
}
