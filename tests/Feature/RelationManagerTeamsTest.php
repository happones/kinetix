<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Actions\AssociateAction;
use Happones\Kinetix\Actions\AttachAction;
use Happones\Kinetix\Actions\CreateAction;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Resources\RelationManager;
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Support\KinetixTeams;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionServiceProvider;

class RmtTeam extends Model
{
    protected $table = 'rmt_teams';

    public $timestamps = false;

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

class RmtUser extends Authenticatable
{
    protected $table = 'rmt_users';

    public $timestamps = false;

    protected $guarded = [];

    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(RmtTeam::class, 'current_team_id');
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(RmtTeam::class, 'rmt_team_user', 'user_id', 'team_id');
    }
}

class RmtStudent extends Model
{
    protected $table = 'rmt_students';

    public $timestamps = false;

    protected $guarded = [];

    public function tutors()
    {
        return $this->belongsToMany(RmtTutor::class, 'rmt_student_tutor', 'student_id', 'tutor_id');
    }

    public function grades()
    {
        return $this->hasMany(RmtGrade::class, 'student_id');
    }
}

class RmtTutor extends Model
{
    protected $table = 'rmt_tutors';

    public $timestamps = false;

    protected $guarded = [];
}

class RmtGrade extends Model
{
    protected $table = 'rmt_grades';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * The exact hook shape `kinetix:make-resource --team` scaffolds.
 */
class RmtTutorResource extends Resource
{
    protected static ?string $model = RmtTutor::class;

    public static function getEloquentQuery(): Builder
    {
        return RmtTutor::where('team_id', KinetixTeams::currentTeamKey());
    }

    public static function mutateFormDataBeforeSave(array $data, string $operation, ?Model $record = null): array
    {
        if ($operation === 'create') {
            $data['team_id'] = KinetixTeams::currentTeamKey();
        } else {
            unset($data['team_id']);
        }

        return $data;
    }
}

class RmtGradeResource extends Resource
{
    protected static ?string $model = RmtGrade::class;

    public static function getEloquentQuery(): Builder
    {
        return RmtGrade::where('team_id', KinetixTeams::currentTeamKey());
    }

    public static function mutateFormDataBeforeSave(array $data, string $operation, ?Model $record = null): array
    {
        if ($operation === 'create') {
            $data['team_id'] = KinetixTeams::currentTeamKey();
        } else {
            unset($data['team_id']);
        }

        return $data;
    }
}

class RmtTutorsManager extends RelationManager
{
    protected static string $relationship = 'tutors';

    protected static ?string $relatedResource = RmtTutorResource::class;

    protected static ?string $recordTitleAttribute = 'name';

    public function form(Form $form): Form
    {
        return $form->schema([TextInput::make('name')->required()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('name')])
            ->toolbarActions([CreateAction::make()->modal('create'), AttachAction::make()]);
    }
}

class RmtGradesManager extends RelationManager
{
    protected static string $relationship = 'grades';

    protected static ?string $relatedResource = RmtGradeResource::class;

    protected static ?string $recordTitleAttribute = 'title';

    public function form(Form $form): Form
    {
        return $form->schema([TextInput::make('title')->required()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('title')])
            ->toolbarActions([CreateAction::make()->modal('create'), AssociateAction::make()]);
    }
}

/**
 * The relation endpoints under the `{current_team}` segment, with resources
 * shaped like the team scaffold: the team comes from the URL, not the user.
 */
class RelationManagerTeamsTest extends TestCase
{
    private RmtTeam $teamA;

    private RmtTeam $teamB;

    private RmtStudent $student;

    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        // Team-prefixed groups carry the `kinetix.permissions.team` middleware,
        // which resolves spatie's PermissionRegistrar — its provider must boot.
        return [...parent::getPackageProviders($app), PermissionServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.teams', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('rmt_teams', function (Blueprint $table) {
            $table->increments('id');
            $table->string('slug');
        });

        Schema::create('rmt_users', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('current_team_id');
        });

        Schema::create('rmt_team_user', function (Blueprint $table) {
            $table->unsignedInteger('team_id');
            $table->unsignedInteger('user_id');
        });

        Schema::create('rmt_students', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('team_id');
        });

        Schema::create('rmt_tutors', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('team_id')->nullable();
            $table->string('name');
        });

        Schema::create('rmt_student_tutor', function (Blueprint $table) {
            $table->unsignedInteger('student_id');
            $table->unsignedInteger('tutor_id');
        });

        Schema::create('rmt_grades', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('student_id')->nullable();
            $table->unsignedInteger('team_id')->nullable();
            $table->string('title');
        });

        $this->teamA = RmtTeam::create(['slug' => 'team-a']);
        $this->teamB = RmtTeam::create(['slug' => 'team-b']);

        $user = RmtUser::create(['current_team_id' => $this->teamA->id]);
        $user->teams()->attach([$this->teamA->id, $this->teamB->id]);
        $this->actingAs($user);

        $this->student = RmtStudent::create(['team_id' => $this->teamA->id]);
    }

    /**
     * @param class-string<RelationManager> $manager
     */
    private function descriptorFor(string $manager): string
    {
        return (string) $manager::make($this->student)->toData()->descriptor;
    }

    private function teamRoute(string $name): string
    {
        return route("kinetix.relations.{$name}", ['current_team' => 'team-a']);
    }

    public function test_the_pickers_follow_the_team_in_the_url(): void
    {
        $mine = RmtTutor::create(['team_id' => $this->teamA->id, 'name' => 'Ana']);
        RmtTutor::create(['team_id' => $this->teamB->id, 'name' => 'Carla']);
        RmtGrade::create(['team_id' => $this->teamA->id, 'title' => 'Math']);
        RmtGrade::create(['team_id' => $this->teamB->id, 'title' => 'History']);

        $tutors = $this->descriptorFor(RmtTutorsManager::class);

        $this->postJson($this->teamRoute('attachable'), ['descriptor' => $tutors])
            ->assertOk()
            ->assertJsonPath('options', [['id' => $mine->id, 'label' => 'Ana']]);

        $this->postJson($this->teamRoute('associable'), ['descriptor' => $this->descriptorFor(RmtGradesManager::class)])
            ->assertOk()
            ->assertJsonCount(1, 'options')
            ->assertJsonPath('options.0.label', 'Math');
    }

    public function test_creating_from_a_manager_stamps_the_team_in_the_url(): void
    {
        $this->from('/x')->post($this->teamRoute('record.store'), [
            'token' => $this->descriptorFor(RmtGradesManager::class),
            'data'  => ['title' => 'Math'],
        ])->assertRedirect('/x');

        $this->from('/x')->post($this->teamRoute('record.store'), [
            'token' => $this->descriptorFor(RmtTutorsManager::class),
            'data'  => ['name' => 'Ana'],
        ])->assertRedirect('/x');

        $grade = RmtGrade::sole();
        $this->assertSame($this->teamA->id, (int) $grade->team_id);
        $this->assertSame($this->student->id, (int) $grade->student_id);

        // BelongsToMany: created with the team AND attached to the parent.
        $tutor = RmtTutor::sole();
        $this->assertSame($this->teamA->id, (int) $tutor->team_id);
        $this->assertSame([$tutor->id], $this->student->tutors()->pluck('rmt_tutors.id')->all());
    }
}
