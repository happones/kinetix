<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Actions\AttachAction;
use Happones\Kinetix\Actions\DetachAction;
use Happones\Kinetix\Forms\Components\TextInput;
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

class RmaProject extends Model
{
    protected $table = 'rma_projects';

    public $timestamps = false;

    protected $guarded = [];

    public function tags()
    {
        return $this->belongsToMany(RmaTag::class, 'rma_project_tag', 'project_id', 'tag_id')
            ->withPivot('role');
    }

    public function notes()
    {
        return $this->hasMany(RmaNote::class, 'project_id');
    }
}

class RmaTag extends Model
{
    protected $table = 'rma_tags';

    public $timestamps = false;

    protected $guarded = [];
}

class RmaNote extends Model
{
    protected $table = 'rma_notes';

    public $timestamps = false;

    protected $guarded = [];
}

class RmaUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class RmaProjectPolicy
{
    public function update(RmaUser $user, RmaProject $project): bool
    {
        return (bool) $user->can_edit;
    }
}

class TagsManager extends RelationManager
{
    protected static string $relationship = 'tags';

    protected static ?string $recordTitleAttribute = 'name';

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('name')])
            ->toolbarActions([AttachAction::make()])
            ->recordActions([DetachAction::make()]);
    }
}

class ReadOnlyTagsManager extends TagsManager
{
    protected static bool $readOnly = true;

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')])
            ->recordActions([DetachAction::make()]);
    }
}

class NotesWithAttachManager extends RelationManager
{
    protected static string $relationship = 'notes';

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('body')])
            ->toolbarActions([AttachAction::make()]);
    }
}

class PivotFormTagsManager extends RelationManager
{
    protected static string $relationship = 'tags';

    protected static ?string $recordTitleAttribute = 'name';

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('name')])
            ->toolbarActions([
                AttachAction::make()->form([
                    TextInput::make('role')->required(),
                ]),
            ]);
    }
}

class BadPivotFormTagsManager extends RelationManager
{
    protected static string $relationship = 'tags';

    protected static ?string $recordTitleAttribute = 'name';

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('name')])
            ->toolbarActions([
                AttachAction::make()->form([
                    TextInput::make('nope'),
                ]),
            ]);
    }
}

class RelationAttachDetachTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('rma_projects', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('rma_tags', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('rma_project_tag', function (Blueprint $table) {
            $table->unsignedInteger('project_id');
            $table->unsignedInteger('tag_id');
            $table->string('role')->nullable();
        });

        Schema::create('rma_notes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('project_id');
            $table->string('body');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->boolean('can_edit')->default(true);
        });
    }

    private function descriptorFor(RmaProject $project, RmaUser $user): string
    {
        $this->actingAs($user);

        return (string) TagsManager::make($project)->toData()->descriptor;
    }

    public function test_the_manager_wires_attach_and_detach_events_and_mints_a_descriptor(): void
    {
        $project = RmaProject::create(['name' => 'Kinetix']);

        $data = TagsManager::make($project)->toData();

        $this->assertNotNull($data->descriptor);

        $toolbar = $data->table->toolbarActions[0] ?? null;
        $this->assertSame('open-attach', $toolbar->dispatchEvent);
        $this->assertSame('tags', $toolbar->dispatchData['relationship'] ?? null);

        $row = $data->table->recordActions[0] ?? null;
        $this->assertSame('detach-relation', $row->dispatchEvent);
        $this->assertTrue($row->requiresConfirmation);
    }

    public function test_attachable_lists_only_unattached_records_and_searches(): void
    {
        $user    = RmaUser::create([]);
        $project = RmaProject::create(['name' => 'Kinetix']);

        $attached = RmaTag::create(['name' => 'php']);
        RmaTag::create(['name' => 'vue']);
        RmaTag::create(['name' => 'rust']);
        $project->tags()->attach($attached->id);

        $descriptor = $this->descriptorFor($project, $user);

        $labels = collect(
            $this->postJson(route('kinetix.relations.attachable'), [
                'descriptor' => $descriptor,
            ])->assertOk()->json('options'),
        )->pluck('label')->all();

        $this->assertSame(['rust', 'vue'], $labels);

        $filtered = collect(
            $this->postJson(route('kinetix.relations.attachable'), [
                'descriptor' => $descriptor,
                'search'     => 'vu',
            ])->assertOk()->json('options'),
        )->pluck('label')->all();

        $this->assertSame(['vue'], $filtered);
    }

    public function test_attach_links_existing_records_and_ignores_ghost_ids(): void
    {
        $user    = RmaUser::create([]);
        $project = RmaProject::create(['name' => 'Kinetix']);
        $tag     = RmaTag::create(['name' => 'php']);

        $this->postJson(route('kinetix.relations.attach'), [
            'descriptor' => $this->descriptorFor($project, $user),
            'ids'        => [$tag->id, 999],
        ])->assertOk()->assertJson(['attached' => 1]);

        $this->assertSame(['php'], $project->tags()->pluck('name')->all());
    }

    public function test_detach_removes_the_pivot_row_only(): void
    {
        $user    = RmaUser::create([]);
        $project = RmaProject::create(['name' => 'Kinetix']);
        $tag     = RmaTag::create(['name' => 'php']);
        $project->tags()->attach($tag->id);

        $this->postJson(route('kinetix.relations.detach'), [
            'descriptor' => $this->descriptorFor($project, $user),
            'record'     => ['id' => $tag->id],
        ])->assertOk();

        $this->assertSame(0, $project->tags()->count());
        $this->assertNotNull($tag->fresh());
    }

    public function test_a_descriptor_minted_for_another_user_is_rejected(): void
    {
        $owner    = RmaUser::create([]);
        $attacker = RmaUser::create([]);
        $project  = RmaProject::create(['name' => 'Kinetix']);
        $tag      = RmaTag::create(['name' => 'php']);

        $descriptor = $this->descriptorFor($project, $owner);

        $this->actingAs($attacker)
            ->postJson(route('kinetix.relations.attach'), [
                'descriptor' => $descriptor,
                'ids'        => [$tag->id],
            ])->assertForbidden();

        $this->assertSame(0, $project->tags()->count());
    }

    public function test_the_parents_update_policy_gates_the_endpoints(): void
    {
        Gate::policy(RmaProject::class, RmaProjectPolicy::class);

        $user    = RmaUser::create(['can_edit' => false]);
        $project = RmaProject::create(['name' => 'Kinetix']);
        $tag     = RmaTag::create(['name' => 'php']);

        $this->postJson(route('kinetix.relations.attach'), [
            'descriptor' => $this->descriptorFor($project, $user),
            'ids'        => [$tag->id],
        ])->assertForbidden();
    }

    public function test_attach_form_pivot_fields_serialize_validate_and_write(): void
    {
        $user    = RmaUser::create([]);
        $project = RmaProject::create(['name' => 'Kinetix']);
        $php     = RmaTag::create(['name' => 'php']);
        $vue     = RmaTag::create(['name' => 'vue']);

        $this->actingAs($user);
        $data = PivotFormTagsManager::make($project)->toData();

        // The pivot form ships with the manager so the attach modal can render it.
        $this->assertNotNull($data->attachForm);
        $this->assertArrayHasKey('role', $data->attachForm['data'] ?? []);

        // Missing required pivot data → 422, nothing attached.
        $this->postJson(route('kinetix.relations.attach'), [
            'descriptor' => $data->descriptor,
            'ids'        => [$php->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertSame(0, $project->tags()->count());

        // The validated state lands on the pivot row of EVERY attached record.
        $this->postJson(route('kinetix.relations.attach'), [
            'descriptor' => $data->descriptor,
            'ids'        => [$php->id, $vue->id],
            'pivot'      => ['role' => 'writer'],
        ])->assertOk()->assertJson(['attached' => 2]);

        $this->assertSame(
            ['writer', 'writer'],
            $project->tags()->pluck('rma_project_tag.role')->all(),
        );
    }

    public function test_attach_pivot_data_is_ignored_without_a_form(): void
    {
        $user    = RmaUser::create([]);
        $project = RmaProject::create(['name' => 'Kinetix']);
        $tag     = RmaTag::create(['name' => 'php']);

        // TagsManager's AttachAction declares no form — submitted pivot data
        // must never reach the pivot row.
        $this->postJson(route('kinetix.relations.attach'), [
            'descriptor' => $this->descriptorFor($project, $user),
            'ids'        => [$tag->id],
            'pivot'      => ['role' => 'smuggled'],
        ])->assertOk();

        $this->assertNull($project->tags()->first()->pivot->role);
    }

    public function test_an_attach_form_field_outside_with_pivot_throws(): void
    {
        $project = RmaProject::create(['name' => 'Kinetix']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not a pivot column');

        BadPivotFormTagsManager::make($project)->toData();
    }

    public function test_attach_action_on_a_non_belongs_to_many_relation_throws(): void
    {
        $project = RmaProject::create(['name' => 'Kinetix']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('require a BelongsToMany relation');

        NotesWithAttachManager::make($project)->toData();
    }

    public function test_read_only_strips_every_action(): void
    {
        $project = RmaProject::create(['name' => 'Kinetix']);
        $project->tags()->attach(RmaTag::create(['name' => 'php'])->id);

        $data = ReadOnlyTagsManager::make($project)->toData();

        $this->assertSame([], $data->table->recordActions);
        $this->assertSame([], $data->table->toolbarActions);
        $this->assertSame([], $data->table->bulkActions);
    }
}
