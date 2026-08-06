<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Actions\ActionGroup;
use Happones\Kinetix\Actions\CreateAction;
use Happones\Kinetix\Actions\EditAction;
use Happones\Kinetix\Actions\ExportAction;
use Happones\Kinetix\Actions\ImportAction;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Resources\RelationManager;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Columns\TextInputColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
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
        return $this->belongsToMany(RtpTag::class, 'rtp_project_tag', 'project_id', 'tag_id')
            ->withPivot('role');
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

class RtpPivotTagsManager extends RelationManager
{
    protected static string $relationship = 'tags';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name'),
            TextColumn::make('pivot.role')->searchable()->sortable(),
        ]);
    }
}

class RtpEditablePivotManager extends RelationManager
{
    protected static string $relationship = 'tags';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextInputColumn::make('pivot.role'),
        ]);
    }
}

class RtpLazyTasksManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static bool $isLazy = true;

    public function getBadge(): int|string|null
    {
        return $this->getRelationshipQuery()->count();
    }

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')]);
    }
}

class RtpEditableUndeclaredPivotManager extends RelationManager
{
    protected static string $relationship = 'tags';

    public function table(Table $table): Table
    {
        // `created_at` exists on the pivot TABLE but not in withPivot() —
        // the cell-update endpoint could never write it.
        return $table->columns([
            TextInputColumn::make('pivot.created_at'),
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
        // No ->exporter(): a bare export can't be relation-scoped.
        return $table
            ->columns([TextColumn::make('title')])
            ->footerActions([ExportAction::make('export')]);
    }
}

class RtpFooterImportManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('title')])
            ->footerActions([ImportAction::make('import')]);
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
            $table->string('role')->nullable();
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

    public function test_an_export_action_without_an_exporter_throws_at_serialize_time(): void
    {
        $project = RtpProject::create(['name' => 'Kinetix']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be wired via ->exporter()');

        RtpFooterExportManager::make($project)->toData();
    }

    public function test_an_import_action_inside_a_manager_throws_at_serialize_time(): void
    {
        $project = RtpProject::create(['name' => 'Kinetix']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ImportAction is not supported inside a relation manager');

        RtpFooterImportManager::make($project)->toData();
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

    public function test_pivot_columns_display_sort_and_search(): void
    {
        $project = RtpProject::create(['name' => 'Kinetix']);
        $php     = RtpTag::create(['name' => 'php']);
        $vue     = RtpTag::create(['name' => 'vue']);
        $project->tags()->attach([
            $php->id => ['role' => 'backend'],
            $vue->id => ['role' => 'frontend'],
        ]);

        // Display: `pivot.role` resolves through a REAL hydrated Pivot model.
        $records = RtpPivotTagsManager::make($project)->toData()->table->records;
        $byName  = collect($records)->keyBy(fn ($r) => $r->values['name']);

        $this->assertSame('backend', $byName['php']->values['pivot.role']);
        $this->assertSame('frontend', $byName['vue']->values['pivot.role']);
        // Row ids stay the RELATED keys even with pivot selects aboard.
        $this->assertSame($php->id, $byName['php']->id);

        // Sort: qualified against the JOINED pivot table.
        request()->merge(['tags_sort' => 'pivot.role', 'tags_direction' => 'desc']);
        $sorted = RtpPivotTagsManager::make($project)->toData()->table->records;
        $this->assertSame('frontend', $sorted[0]->values['pivot.role']);

        // Search: matches the pivot value, not the related model's columns.
        request()->merge(['tags_search' => 'backend', 'tags_sort' => null]);
        $found = RtpPivotTagsManager::make($project)->toData()->table->records;
        $this->assertCount(1, $found);
        $this->assertSame('php', $found[0]->values['name']);
    }

    public function test_an_editable_pivot_column_writes_the_pivot_row_not_the_related_model(): void
    {
        $user    = RtpUser::create([]);
        $project = RtpProject::create(['name' => 'Kinetix']);
        $tag     = RtpTag::create(['name' => 'php']);
        $project->tags()->attach($tag->id, ['role' => 'backend']);

        $this->actingAs($user);
        $data = RtpEditablePivotManager::make($project)->toData();

        $this->postJson(route('kinetix.tables.cell-update'), [
            'model'    => $data->table->model,
            'recordId' => $tag->id,
            'column'   => 'pivot.role',
            'value'    => 'frontend',
        ])->assertOk();

        // The pivot row changed; the related model has no `role` column and
        // never sees the write.
        $this->assertSame('frontend', $project->tags()->first()->pivot->role);
        $this->assertSame('php', $tag->fresh()->name);
    }

    public function test_a_pivot_cell_write_for_an_unattached_record_is_a_404(): void
    {
        $user    = RtpUser::create([]);
        $project = RtpProject::create(['name' => 'Kinetix']);
        $project->tags()->attach(RtpTag::create(['name' => 'php'])->id, ['role' => 'backend']);
        $loose = RtpTag::create(['name' => 'vue']); // exists, NOT attached

        $this->actingAs($user);
        $data = RtpEditablePivotManager::make($project)->toData();

        $this->postJson(route('kinetix.tables.cell-update'), [
            'model'    => $data->table->model,
            'recordId' => $loose->id,
            'column'   => 'pivot.role',
            'value'    => 'smuggled',
        ])->assertNotFound();
    }

    public function test_a_lazy_manager_serializes_a_stub_until_its_relation_param_matches(): void
    {
        $project = RtpProject::create(['name' => 'Kinetix']);
        $project->tasks()->createMany([['title' => 'Alpha'], ['title' => 'Beta']]);

        // No ?relation= (initial page render): tab stub only — no table, no
        // descriptor — but title and badge so the tab renders complete.
        $stub = RtpLazyTasksManager::make($project)->toData();

        $this->assertTrue($stub->deferred);
        $this->assertNull($stub->table);
        $this->assertNull($stub->descriptor);
        $this->assertSame('Tasks', $stub->title);
        $this->assertSame(2, $stub->badge);

        // Another tab active: still a stub.
        request()->merge(['relation' => 'other']);
        $this->assertTrue(RtpLazyTasksManager::make($project)->toData()->deferred);

        // Its own tab active: the full payload.
        request()->merge(['relation' => 'tasks']);
        $full = RtpLazyTasksManager::make($project)->toData();

        $this->assertFalse($full->deferred);
        $this->assertNotNull($full->table);
        $this->assertCount(2, $full->table->records);
    }

    public function test_a_lazy_stub_runs_no_table_queries(): void
    {
        $project = RtpProject::create(['name' => 'Kinetix']);
        $project->tasks()->create(['title' => 'Alpha']);

        DB::enableQueryLog();

        RtpLazyTasksManager::make($project)->toData();

        // Only getBadge()'s own count — none of the table's record/summary
        // queries. That single query is the whole point of keeping badges
        // cheap on lazy managers.
        $queries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_contains($sql, 'rtp_tasks'));

        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertStringContainsString('count', strtolower((string) $queries->first()));
    }

    public function test_an_eager_manager_is_never_deferred(): void
    {
        $project = RtpProject::create(['name' => 'Kinetix']);

        $data = RtpTagsManager::make($project)->toData();

        $this->assertFalse($data->deferred);
        $this->assertNotNull($data->table);
    }

    public function test_an_editable_pivot_column_outside_with_pivot_throws_at_serialize_time(): void
    {
        $project = RtpProject::create(['name' => 'Kinetix']);
        $project->tags()->attach(RtpTag::create(['name' => 'php'])->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not a withPivot() column');

        RtpEditableUndeclaredPivotManager::make($project)->toData();
    }
}
