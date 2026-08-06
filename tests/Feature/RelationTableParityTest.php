<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Actions\ActionGroup;
use Happones\Kinetix\Actions\CreateAction;
use Happones\Kinetix\Actions\EditAction;
use Happones\Kinetix\Actions\ExportAction;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
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

class RtpProject extends Model
{
    protected $table = 'rtp_projects';

    public $timestamps = false;

    protected $guarded = [];

    public function tags()
    {
        return $this->belongsToMany(RtpTag::class, 'rtp_project_tag', 'project_id', 'tag_id');
    }

    public function tasks()
    {
        // A relation that ships its own default order — a clicked header
        // sort must still win over it.
        return $this->hasMany(RtpTask::class, 'project_id')->orderBy('title');
    }
}

class RtpTag extends Model
{
    protected $table = 'rtp_tags';

    public $timestamps = false;

    protected $guarded = [];
}

class RtpTask extends Model
{
    protected $table = 'rtp_tasks';

    public $timestamps = false;

    protected $guarded = [];
}

class RtpUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class RtpTaskPolicy
{
    public function create(RtpUser $user): bool
    {
        return (bool) $user->can_edit;
    }

    public function update(RtpUser $user, RtpTask $task): bool
    {
        return true;
    }
}

class RtpTagsManager extends RelationManager
{
    protected static string $relationship = 'tags';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
        ]);
    }
}

class RtpReorderableTasksManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('title')->sortable()])
            ->reorderable('sort_order');
    }
}

class RtpFooterExportManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('title')])
            ->footerActions([ExportAction::make('export')]);
    }
}

class RtpReadOnlyFooterManager extends RelationManager
{
    protected static bool $readOnly = true;

    protected static string $relationship = 'tasks';

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('title')])
            ->footerActions([
                Action::make('boom')->label('Boom'),
            ]);
    }
}

class RtpCreatableTasksManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    public function form(Form $form): Form
    {
        return $form->schema([TextInput::make('title')->required()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('title')])
            ->toolbarActions([CreateAction::make()->modal('create')])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()->modal('edit'),
                ]),
            ]);
    }
}

class RelationTableParityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('rtp_projects', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('rtp_tags', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        // The pivot carries its OWN id + timestamps: without a qualified
        // select they clobber the related model's columns at hydration.
        Schema::create('rtp_project_tag', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('project_id');
            $table->unsignedInteger('tag_id');
            $table->timestamps();
        });

        Schema::create('rtp_tasks', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('project_id')->nullable();
            $table->string('title');
            $table->integer('sort_order')->default(0);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->boolean('can_edit')->default(true);
        });
    }

    public function test_belongs_to_many_rows_carry_the_related_key_not_the_pivot_id(): void
    {
        $project = RtpProject::create(['name' => 'Kinetix']);

        // Offset the pivot ids so pivot.id ≠ tags.id for every row.
        RtpTag::create(['name' => 'zero']); // tag 1, never attached
        $php = RtpTag::create(['name' => 'php']);  // tag 2
        $vue = RtpTag::create(['name' => 'vue']);  // tag 3
        $project->tags()->attach([$php->id, $vue->id]); // pivot ids 1, 2

        $records = RtpTagsManager::make($project)->toData()->table->records;
        $ids     = array_map(fn ($r) => $r->id, $records);

        sort($ids);
        $this->assertSame([$php->id, $vue->id], $ids);
    }

    public function test_search_and_sort_survive_the_pivot_join(): void
    {
        $project = RtpProject::create(['name' => 'Kinetix']);
        $project->tags()->attach([
            RtpTag::create(['name' => 'php'])->id,
            RtpTag::create(['name' => 'vue'])->id,
        ]);

        // `id`/`created_at` exist on BOTH sides of the join — unqualified
        // search/sort SQL would be ambiguous and 500.
        request()->merge(['tags_search' => 'vu', 'tags_sort' => 'name', 'tags_direction' => 'desc']);

        $records = RtpTagsManager::make($project)->toData()->table->records;

        $this->assertCount(1, $records);
        $this->assertSame('vue', $records[0]->values['name']);
    }

    public function test_a_clicked_sort_wins_over_the_relations_own_order(): void
    {
        $project = RtpProject::create(['name' => 'Kinetix']);
        $project->tasks()->createMany([
            ['title' => 'Alpha'],
            ['title' => 'Zulu'],
        ]);

        // The relation ships orderBy('title' asc); the user sorts desc.
        request()->merge(['tasks_sort' => 'title', 'tasks_direction' => 'desc']);

        $records = RtpReorderableTasksManager::make($project)->toData()->table->records;

        $this->assertSame('Zulu', $records[0]->values['title']);
    }

    public function test_reorder_inside_a_relation_manager_is_parent_bound(): void
    {
        $user    = RtpUser::create([]);
        $mine    = RtpProject::create(['name' => 'Mine']);
        $other   = RtpProject::create(['name' => 'Other']);
        $a       = $mine->tasks()->create(['title' => 'A', 'sort_order' => 1]);
        $b       = $mine->tasks()->create(['title' => 'B', 'sort_order' => 2]);
        $foreign = $other->tasks()->create(['title' => 'F', 'sort_order' => 1]);

        $this->actingAs($user);
        $data = RtpReorderableTasksManager::make($mine)->toData();

        $this->postJson(route('kinetix.tables.reorder'), [
            'model' => $data->table->model,
            // The foreign id must be silently dropped (out of relation scope).
            'ids' => [$b->id, $a->id, $foreign->id],
        ])->assertOk();

        $this->assertSame(1, (int) $b->fresh()->sort_order);
        $this->assertSame(2, (int) $a->fresh()->sort_order);
        $this->assertSame(1, (int) $foreign->fresh()->sort_order);
    }

    public function test_read_only_strips_footer_actions_too(): void
    {
        $project = RtpProject::create(['name' => 'Kinetix']);

        $data = RtpReadOnlyFooterManager::make($project)->toData();

        $this->assertSame([], $data->table->footerActions);
    }

    public function test_an_export_action_inside_a_manager_throws_at_serialize_time(): void
    {
        $project = RtpProject::create(['name' => 'Kinetix']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not supported inside a relation manager');

        RtpFooterExportManager::make($project)->toData();
    }

    public function test_the_create_modal_action_inherits_the_child_models_policy(): void
    {
        Gate::policy(RtpTask::class, RtpTaskPolicy::class);

        $project = RtpProject::create(['name' => 'Kinetix']);

        // Denied create → the toolbar Create button is dropped entirely.
        $this->actingAs(RtpUser::create(['can_edit' => false]));
        $denied = RtpCreatableTasksManager::make($project)->toData();
        $this->assertSame([], $denied->table->toolbarActions);

        // Allowed create → it renders. No new permissions were declared
        // anywhere: the CHILD model's own policy governs the manager.
        $this->actingAs(RtpUser::create(['can_edit' => true]));
        $allowed = RtpCreatableTasksManager::make($project)->toData();
        $this->assertCount(1, $allowed->table->toolbarActions);
        $this->assertSame('create', $allowed->table->toolbarActions[0]->name);
    }
}
