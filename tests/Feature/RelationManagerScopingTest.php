<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Actions\AssociateAction;
use Happones\Kinetix\Actions\AttachAction;
use Happones\Kinetix\Actions\CreateAction;
use Happones\Kinetix\Actions\DeleteAction;
use Happones\Kinetix\Actions\DetachAction;
use Happones\Kinetix\Actions\DissociateAction;
use Happones\Kinetix\Actions\EditAction;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Resources\RelationManager;
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class RmsUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class RmsStudent extends Model
{
    protected $table = 'rms_students';

    public $timestamps = false;

    protected $guarded = [];

    public function tutors()
    {
        return $this->belongsToMany(RmsTutor::class, 'rms_student_tutor', 'student_id', 'tutor_id');
    }

    public function grades()
    {
        return $this->hasMany(RmsGrade::class, 'student_id');
    }
}

class RmsTutor extends Model
{
    protected $table = 'rms_tutors';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => $this->first_name.' '.$this->last_name);
    }

    public function profile()
    {
        return $this->belongsTo(RmsProfile::class, 'profile_id');
    }
}

class RmsProfile extends Model
{
    protected $table = 'rms_profiles';

    public $timestamps = false;

    protected $guarded = [];
}

class RmsGrade extends Model
{
    protected $table = 'rms_grades';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * The team-scoped resources a school app declares: reads limited to the
 * signed-in user's team, `team_id` stamped on create.
 */
class RmsTutorResource extends Resource
{
    protected static ?string $model = RmsTutor::class;

    public static function getEloquentQuery(): Builder
    {
        return RmsTutor::where('team_id', request()->user()->team_id);
    }
}

class RmsGradeResource extends Resource
{
    protected static ?string $model = RmsGrade::class;

    public static function getEloquentQuery(): Builder
    {
        return RmsGrade::where('team_id', request()->user()->team_id);
    }

    public static function mutateFormDataBeforeSave(array $data, string $operation, ?Model $record = null): array
    {
        if ($operation === 'create') {
            $data['team_id'] = request()->user()->team_id;
        }

        if ($operation === 'edit' && $record !== null) {
            $data['note'] = 'edited:'.$record->getKey();
        }

        return $data;
    }
}

class RmsTutorsManager extends RelationManager
{
    protected static string $relationship = 'tutors';

    protected static ?string $relatedResource = RmsTutorResource::class;

    // An accessor title: labels read it, search/sort use the real columns.
    protected static ?string $recordTitleAttribute = 'full_name';

    protected static array $recordSelectSearchColumns = ['last_name', 'first_name'];

    public function form(Form $form): Form
    {
        return $form->schema([TextInput::make('first_name')->required()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('first_name')])
            ->toolbarActions([AttachAction::make()])
            ->recordActions([
                EditAction::make()->modal('edit'),
                DeleteAction::make()->modal('delete'),
                DetachAction::make(),
            ]);
    }
}

class RmsGradesManager extends RelationManager
{
    protected static string $relationship = 'grades';

    protected static ?string $relatedResource = RmsGradeResource::class;

    protected static ?string $recordTitleAttribute = 'title';

    public function form(Form $form): Form
    {
        return $form->schema([TextInput::make('title')->required()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('title')])
            ->toolbarActions([CreateAction::make()->modal('create'), AssociateAction::make()])
            ->recordActions([EditAction::make()->modal('edit'), DissociateAction::make()]);
    }
}

/**
 * No related resource: the manager scopes and stamps from the parent itself.
 */
class RmsParentScopedGradesManager extends RmsGradesManager
{
    protected static ?string $relatedResource = null;

    public function getRelatedQuery(): Builder
    {
        return RmsGrade::where('team_id', $this->parent->team_id);
    }

    public function mutateFormDataBeforeSave(array $data, string $operation, ?Model $record = null): array
    {
        return [...$data, 'team_id' => $this->parent->team_id];
    }
}

class RmsMismatchedResourceManager extends RmsGradesManager
{
    protected static ?string $relatedResource = RmsTutorResource::class;
}

/**
 * A hook that tries to re-parent the new record: the relationship's FK must win.
 */
class RmsReparentingGradesManager extends RmsGradesManager
{
    public function mutateFormDataBeforeSave(array $data, string $operation, ?Model $record = null): array
    {
        return [...parent::mutateFormDataBeforeSave($data, $operation, $record), 'student_id' => 999];
    }
}

/**
 * Labelled and searched through a relation column.
 */
class RmsProfileTitledTutorsManager extends RmsTutorsManager
{
    protected static ?string $recordTitleAttribute = 'profile.display_name';

    protected static array $recordSelectSearchColumns = ['profile.display_name'];
}

class RelationManagerScopingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('team_id');
        });

        Schema::create('rms_students', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('team_id');
            $table->string('name');
        });

        Schema::create('rms_tutors', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('team_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->unsignedInteger('profile_id')->nullable();
        });

        Schema::create('rms_profiles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('display_name');
        });

        // A pivot with its own id: the record lookups must never let it
        // clobber the tutor's id.
        Schema::create('rms_student_tutor', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('student_id');
            $table->unsignedInteger('tutor_id');
        });

        Schema::create('rms_grades', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('student_id')->nullable();
            $table->unsignedInteger('team_id')->nullable();
            $table->string('title');
            $table->string('note')->nullable();
        });
    }

    /**
     * @param class-string<RelationManager> $manager
     */
    private function descriptorFor(string $manager, RmsStudent $student, RmsUser $user): string
    {
        $this->actingAs($user);

        return (string) $manager::make($student)->toData()->descriptor;
    }

    public function test_the_attach_picker_lists_only_the_related_resources_records(): void
    {
        $user    = RmsUser::create(['team_id' => 1]);
        $student = RmsStudent::create(['team_id' => 1, 'name' => 'Leo']);

        RmsTutor::create(['team_id' => 1, 'first_name' => 'Ana', 'last_name' => 'Lopez']);
        RmsTutor::create(['team_id' => 1, 'first_name' => 'Bruno', 'last_name' => 'Diaz']);
        RmsTutor::create(['team_id' => 2, 'first_name' => 'Carla', 'last_name' => 'Ruiz']);

        $descriptor = $this->descriptorFor(RmsTutorsManager::class, $student, $user);

        $labels = fn (string $search): array => collect(
            $this->postJson(route('kinetix.relations.attachable'), [
                'descriptor' => $descriptor,
                'search'     => $search,
            ])->assertOk()->json('options'),
        )->pluck('label')->all();

        // Labelled by the accessor, sorted by last name, other team excluded.
        $this->assertSame(['Bruno Diaz', 'Ana Lopez'], $labels(''));
        $this->assertSame(['Ana Lopez'], $labels('lop'));
        $this->assertSame(['Bruno Diaz'], $labels('bru'));
        $this->assertSame([], $labels('Carla'));
        // LIKE wildcards are literal, not "match everything".
        $this->assertSame([], $labels('%'));
    }

    public function test_attach_ignores_ids_outside_the_related_resources_query(): void
    {
        $user    = RmsUser::create(['team_id' => 1]);
        $student = RmsStudent::create(['team_id' => 1, 'name' => 'Leo']);
        $mine    = RmsTutor::create(['team_id' => 1, 'first_name' => 'Ana', 'last_name' => 'Lopez']);
        $foreign = RmsTutor::create(['team_id' => 2, 'first_name' => 'Carla', 'last_name' => 'Ruiz']);

        $this->postJson(route('kinetix.relations.attach'), [
            'descriptor' => $this->descriptorFor(RmsTutorsManager::class, $student, $user),
            'ids'        => [$mine->id, $foreign->id],
        ])->assertOk()->assertJson(['attached' => 1]);

        $this->assertSame([$mine->id], $student->tutors()->pluck('rms_tutors.id')->all());
    }

    public function test_associate_accepts_only_in_scope_orphans(): void
    {
        $user    = RmsUser::create(['team_id' => 1]);
        $student = RmsStudent::create(['team_id' => 1, 'name' => 'Leo']);
        $sibling = RmsStudent::create(['team_id' => 1, 'name' => 'Mia']);

        $orphan  = RmsGrade::create(['team_id' => 1, 'title' => 'Math']);
        $foreign = RmsGrade::create(['team_id' => 2, 'title' => 'History']);
        $owned   = RmsGrade::create(['team_id' => 1, 'title' => 'Art', 'student_id' => $sibling->id]);

        $descriptor = $this->descriptorFor(RmsGradesManager::class, $student, $user);

        $labels = collect(
            $this->postJson(route('kinetix.relations.associable'), [
                'descriptor' => $descriptor,
            ])->assertOk()->json('options'),
        )->pluck('label')->all();

        $this->assertSame(['Math'], $labels);

        // Forged ids: another team's orphan and a sibling's grade.
        $this->postJson(route('kinetix.relations.associate'), [
            'descriptor' => $descriptor,
            'ids'        => [$orphan->id, $foreign->id, $owned->id],
        ])->assertOk()->assertJson(['associated' => 1]);

        $this->assertSame($student->id, (int) $orphan->fresh()->student_id);
        $this->assertNull($foreign->fresh()->student_id);
        $this->assertSame($sibling->id, (int) $owned->fresh()->student_id);
    }

    public function test_creating_from_the_manager_runs_the_related_resources_save_hook(): void
    {
        $user    = RmsUser::create(['team_id' => 1]);
        $student = RmsStudent::create(['team_id' => 1, 'name' => 'Leo']);

        $this->from('/students/1/edit')->post(route('kinetix.relations.record.store'), [
            'token' => $this->descriptorFor(RmsGradesManager::class, $student, $user),
            'data'  => ['title' => 'Math'],
        ])->assertRedirect('/students/1/edit');

        $grade = RmsGrade::sole();
        $this->assertSame(1, (int) $grade->team_id);
        $this->assertSame($student->id, (int) $grade->student_id);
    }

    public function test_the_save_hook_cannot_re_parent_a_created_record(): void
    {
        $user    = RmsUser::create(['team_id' => 1]);
        $student = RmsStudent::create(['team_id' => 1, 'name' => 'Leo']);

        $this->from('/x')->post(route('kinetix.relations.record.store'), [
            'token' => $this->descriptorFor(RmsReparentingGradesManager::class, $student, $user),
            'data'  => ['title' => 'Math'],
        ])->assertRedirect('/x');

        $this->assertSame($student->id, (int) RmsGrade::sole()->student_id);
    }

    public function test_the_picker_labels_and_searches_through_a_relation_column(): void
    {
        $user    = RmsUser::create(['team_id' => 1]);
        $student = RmsStudent::create(['team_id' => 1, 'name' => 'Leo']);

        foreach (['Ms. Lopez', 'Mr. Diaz', 'Dr. Ruiz'] as $name) {
            RmsTutor::create([
                'team_id'    => 1,
                'first_name' => 'x',
                'last_name'  => 'x',
                'profile_id' => RmsProfile::create(['display_name' => $name])->id,
            ]);
        }

        $descriptor = $this->descriptorFor(RmsProfileTitledTutorsManager::class, $student, $user);

        DB::enableQueryLog();

        $labels = collect(
            $this->postJson(route('kinetix.relations.attachable'), [
                'descriptor' => $descriptor,
            ])->assertOk()->json('options'),
        )->pluck('label')->sort()->values()->all();

        $profileQueries = collect(DB::getQueryLog())
            ->filter(fn (array $entry): bool => str_starts_with($entry['query'], 'select * from "rms_profiles"'))
            ->count();

        $this->assertSame(['Dr. Ruiz', 'Mr. Diaz', 'Ms. Lopez'], $labels);
        // One eager load for the whole page, not one query per option.
        $this->assertSame(1, $profileQueries);

        $this->postJson(route('kinetix.relations.attachable'), [
            'descriptor' => $descriptor,
            'search'     => 'diaz',
        ])->assertOk()->assertJsonCount(1, 'options')->assertJsonPath('options.0.label', 'Mr. Diaz');
    }

    public function test_editing_from_the_manager_runs_the_save_hook_with_the_record(): void
    {
        $user    = RmsUser::create(['team_id' => 1]);
        $student = RmsStudent::create(['team_id' => 1, 'name' => 'Leo']);
        $grade   = $student->grades()->create(['team_id' => 1, 'title' => 'Math']);

        $this->from('/x')->put(route('kinetix.relations.record.update'), [
            'token' => $this->descriptorFor(RmsGradesManager::class, $student, $user),
            'id'    => $grade->id,
            'data'  => ['title' => 'Algebra'],
        ])->assertRedirect('/x');

        $grade->refresh();
        $this->assertSame('Algebra', $grade->title);
        $this->assertSame('edited:'.$grade->id, $grade->note);
    }

    public function test_a_manager_can_scope_and_stamp_without_a_related_resource(): void
    {
        $user    = RmsUser::create(['team_id' => 1]);
        $student = RmsStudent::create(['team_id' => 1, 'name' => 'Leo']);

        RmsGrade::create(['team_id' => 1, 'title' => 'Math']);
        RmsGrade::create(['team_id' => 2, 'title' => 'History']);

        $descriptor = $this->descriptorFor(RmsParentScopedGradesManager::class, $student, $user);

        $labels = collect(
            $this->postJson(route('kinetix.relations.associable'), [
                'descriptor' => $descriptor,
            ])->assertOk()->json('options'),
        )->pluck('label')->all();

        $this->assertSame(['Math'], $labels);

        $this->from('/x')->post(route('kinetix.relations.record.store'), [
            'token' => $descriptor,
            'data'  => ['title' => 'Science'],
        ])->assertRedirect('/x');

        $this->assertSame(1, (int) RmsGrade::where('title', 'Science')->sole()->team_id);
    }

    public function test_a_related_resource_for_another_model_throws_at_render(): void
    {
        $this->actingAs(RmsUser::create(['team_id' => 1]));
        $student = RmsStudent::create(['team_id' => 1, 'name' => 'Leo']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('$relatedResource must be a Resource for');

        RmsMismatchedResourceManager::make($student)->toData();
    }

    public function test_belongs_to_many_edit_and_delete_hit_the_record_not_its_pivot_row(): void
    {
        $user    = RmsUser::create(['team_id' => 1]);
        $student = RmsStudent::create(['team_id' => 1, 'name' => 'Leo']);
        $other   = RmsStudent::create(['team_id' => 1, 'name' => 'Mia']);

        $ana   = RmsTutor::create(['team_id' => 1, 'first_name' => 'Ana', 'last_name' => 'Lopez']);
        $bruno = RmsTutor::create(['team_id' => 1, 'first_name' => 'Bruno', 'last_name' => 'Diaz']);
        $carla = RmsTutor::create(['team_id' => 1, 'first_name' => 'Carla', 'last_name' => 'Ruiz']);

        // Pivot ids 1 and 2 go to the other student, so Leo's row for Ana gets
        // pivot id 3 — Carla's tutor id.
        $other->tutors()->attach([$ana->id, $bruno->id]);
        $student->tutors()->attach($ana->id);

        $descriptor = $this->descriptorFor(RmsTutorsManager::class, $student, $user);

        $this->from('/x')->put(route('kinetix.relations.record.update'), [
            'token' => $descriptor,
            'id'    => $ana->id,
            'data'  => ['first_name' => 'Anabel'],
        ])->assertRedirect('/x');

        $this->assertSame('Anabel', $ana->fresh()->first_name);
        $this->assertSame('Carla', $carla->fresh()->first_name);

        $this->from('/x')->delete(route('kinetix.relations.record.destroy'), [
            'token' => $descriptor,
            'id'    => $ana->id,
        ])->assertRedirect('/x');

        $this->assertNull($ana->fresh());
        $this->assertNotNull($carla->fresh());
    }
}
