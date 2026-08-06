<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Actions\ExportAction;
use Happones\Kinetix\Exports\ExportColumn;
use Happones\Kinetix\Exports\Exporter;
use Happones\Kinetix\Exports\Jobs\ExportProcessor;
use Happones\Kinetix\Resources\RelationManager;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class RseProject extends Model
{
    protected $table = 'rse_projects';

    public $timestamps = false;

    protected $guarded = [];

    public function tasks()
    {
        return $this->hasMany(RseTask::class, 'project_id');
    }

    public function tags()
    {
        return $this->belongsToMany(RseTag::class, 'rse_project_tag', 'project_id', 'tag_id');
    }
}

class RseTask extends Model
{
    protected $table = 'rse_tasks';

    public $timestamps = false;

    protected $guarded = [];
}

class RseTag extends Model
{
    protected $table = 'rse_tags';

    public $timestamps = false;

    protected $guarded = [];
}

class RseUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class RseProjectPolicy
{
    public function view(RseUser $user, RseProject $project): bool
    {
        return (bool) $user->can_view;
    }

    public function update(RseUser $user, RseProject $project): bool
    {
        return true;
    }
}

class RseTaskExporter extends Exporter
{
    protected static ?string $model = RseTask::class;

    public static function getColumns(): array
    {
        return [ExportColumn::make('title')];
    }
}

class RseTagExporter extends Exporter
{
    protected static ?string $model = RseTag::class;

    public static function getColumns(): array
    {
        return [ExportColumn::make('name')];
    }
}

class RseTasksExportManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('title')])
            ->toolbarActions([ExportAction::make()->exporter(RseTaskExporter::class)]);
    }
}

class RseTagsExportManager extends RelationManager
{
    protected static string $relationship = 'tags';

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('name')])
            ->toolbarActions([ExportAction::make()->exporter(RseTagExporter::class)]);
    }
}

class RseMismatchedExportManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('title')])
            ->toolbarActions([ExportAction::make()->exporter(RseTagExporter::class)]);
    }
}

class RseBareExportManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('title')])
            ->toolbarActions([ExportAction::make()->request('/custom-export')]);
    }
}

class RelationScopedExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('rse_projects', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('rse_tasks', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('project_id')->nullable();
            $table->string('title');
        });

        Schema::create('rse_tags', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('rse_project_tag', function (Blueprint $table) {
            $table->unsignedInteger('project_id');
            $table->unsignedInteger('tag_id');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->boolean('can_view')->default(true);
        });
    }

    /**
     * @param class-string<RelationManager> $manager
     */
    private function descriptorFor(string $manager, RseProject $project, RseUser $user): string
    {
        $this->actingAs($user);

        return (string) $manager::make($project)->toData()->descriptor;
    }

    public function test_the_manager_wires_the_export_action_to_the_relation_scoped_start_url(): void
    {
        $user    = RseUser::create([]);
        $project = RseProject::create(['name' => 'Kinetix']);

        $this->actingAs($user);
        $data = RseTasksExportManager::make($project)->toData();

        // The descriptor is minted for the export and travels in the start URL.
        $this->assertNotNull($data->descriptor);
        $this->assertStringContainsString('relation=', (string) $data->table->toolbarActions[0]->url);
    }

    public function test_a_relation_scoped_export_only_exports_the_parents_children(): void
    {
        $user  = RseUser::create([]);
        $mine  = RseProject::create(['name' => 'Mine']);
        $other = RseProject::create(['name' => 'Other']);
        $mine->tasks()->createMany([['title' => 'A'], ['title' => 'B']]);
        $other->tasks()->create(['title' => 'Foreign']);

        Queue::fake();

        $this->postJson(route('kinetix.exports.start', [
            'exporter' => RseTaskExporter::token(),
            'relation' => $this->descriptorFor(RseTasksExportManager::class, $mine, $user),
        ]))->assertOk()->assertJson(['status' => 'queued']);

        $parameters = null;
        Queue::assertPushed(ExportProcessor::class, function (ExportProcessor $job) use (&$parameters): bool {
            $parameters = (new \ReflectionProperty($job, 'parameters'))->getValue($job);

            return true;
        });

        // The queued exporter narrows to the parent's children only.
        $titles = (new RseTaskExporter)
            ->withParameters($parameters)
            ->resolveExportQuery()
            ->pluck('title')
            ->all();

        sort($titles);
        $this->assertSame(['A', 'B'], $titles);
    }

    public function test_a_belongs_to_many_export_scopes_through_the_pivot(): void
    {
        $user    = RseUser::create([]);
        $project = RseProject::create(['name' => 'Kinetix']);
        $php     = RseTag::create(['name' => 'php']);
        RseTag::create(['name' => 'loose']);
        $project->tags()->attach($php->id);

        Queue::fake();

        $this->postJson(route('kinetix.exports.start', [
            'exporter' => RseTagExporter::token(),
            'relation' => $this->descriptorFor(RseTagsExportManager::class, $project, $user),
        ]))->assertOk();

        $parameters = null;
        Queue::assertPushed(ExportProcessor::class, function (ExportProcessor $job) use (&$parameters): bool {
            $parameters = (new \ReflectionProperty($job, 'parameters'))->getValue($job);

            return true;
        });

        $this->assertSame(
            ['php'],
            (new RseTagExporter)->withParameters($parameters)->resolveExportQuery()->pluck('name')->all(),
        );
    }

    public function test_bulk_ids_narrow_on_top_of_the_relation_scope(): void
    {
        $mine  = RseProject::create(['name' => 'Mine']);
        $other = RseProject::create(['name' => 'Other']);
        $keep  = $mine->tasks()->create(['title' => 'Keep']);
        $mine->tasks()->create(['title' => 'Skip']);
        $foreign = $other->tasks()->create(['title' => 'Foreign']);

        // Tampered ids can never reach beyond the relation scope.
        $titles = (new RseTaskExporter)
            ->withParameters([
                'ids'      => [$keep->id, $foreign->id],
                'relation' => ['parent' => RseProject::class, 'key' => $mine->id, 'name' => 'tasks'],
            ])
            ->resolveExportQuery()
            ->pluck('title')
            ->all();

        $this->assertSame(['Keep'], $titles);
    }

    public function test_a_parent_deleted_before_the_job_runs_exports_nothing(): void
    {
        $project = RseProject::create(['name' => 'Kinetix']);
        $project->tasks()->create(['title' => 'Orphaned']);
        $key = $project->getKey();
        $project->delete();

        $this->assertSame(0, (new RseTaskExporter)
            ->withParameters(['relation' => ['parent' => RseProject::class, 'key' => $key, 'name' => 'tasks']])
            ->resolveExportQuery()
            ->count());
    }

    public function test_a_descriptor_minted_for_another_user_is_rejected(): void
    {
        $owner    = RseUser::create([]);
        $attacker = RseUser::create([]);
        $project  = RseProject::create(['name' => 'Kinetix']);

        $descriptor = $this->descriptorFor(RseTasksExportManager::class, $project, $owner);

        Queue::fake();

        $this->actingAs($attacker)->postJson(route('kinetix.exports.start', [
            'exporter' => RseTaskExporter::token(),
            'relation' => $descriptor,
        ]))->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_the_parents_view_policy_gates_the_relation_scoped_export(): void
    {
        Gate::policy(RseProject::class, RseProjectPolicy::class);

        $user    = RseUser::create(['can_view' => false]);
        $project = RseProject::create(['name' => 'Kinetix']);

        Queue::fake();

        $this->postJson(route('kinetix.exports.start', [
            'exporter' => RseTaskExporter::token(),
            'relation' => $this->descriptorFor(RseTasksExportManager::class, $project, $user),
        ]))->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_an_exporter_whose_model_differs_from_the_relation_is_rejected(): void
    {
        $user    = RseUser::create([]);
        $project = RseProject::create(['name' => 'Kinetix']);

        // The descriptor names the `tasks` relation; the exporter exports tags.
        $descriptor = $this->descriptorFor(RseTasksExportManager::class, $project, $user);

        Queue::fake();

        $this->postJson(route('kinetix.exports.start', [
            'exporter' => RseTagExporter::token(),
            'relation' => $descriptor,
        ]))->assertUnprocessable();

        Queue::assertNothingPushed();
    }

    public function test_a_mismatched_exporter_throws_at_serialize_time(): void
    {
        $project = RseProject::create(['name' => 'Kinetix']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("the exporter's model must match the relation");

        RseMismatchedExportManager::make($project)->toData();
    }

    public function test_an_export_action_without_an_exporter_throws_at_serialize_time(): void
    {
        $project = RseProject::create(['name' => 'Kinetix']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be wired via ->exporter()');

        RseBareExportManager::make($project)->toData();
    }
}
