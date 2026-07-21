<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Infolists\Components\TextEntry;
use Happones\Kinetix\Infolists\Infolist;
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;

class RecordModalTeam extends Model
{
    protected $table = 'rm_teams';

    public $timestamps = false;

    protected $guarded = [];
}

class RecordModalTeamUser extends Authenticatable
{
    protected $table = 'rm_users';

    public $timestamps = false;

    protected $guarded = [];

    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(RecordModalTeam::class, 'current_team_id');
    }
}

class TeamScopedWidget extends Model
{
    protected $table = 'team_scoped_widgets';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * Mirrors what `kinetix:make-resource --simple --team` scaffolds: the base
 * query scopes every read/write to the current team and `team_id` is stamped
 * on create, keeping the in-table modal endpoint tenant-safe.
 */
class TeamScopedWidgetResource extends Resource
{
    protected static ?string $model = TeamScopedWidget::class;

    public static function table(Table $table): Table
    {
        return $table->recordModals(static::class);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([TextInput::make('name')->required()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([TextEntry::make('name')]);
    }

    public static function getEloquentQuery(): Builder
    {
        return TeamScopedWidget::where('team_id', request()->user()->currentTeam->id);
    }

    public static function mutateFormDataBeforeSave(array $data, string $operation, ?Model $record = null): array
    {
        if ($operation === 'create') {
            $data['team_id'] = request()->user()->currentTeam->id;
        }

        return $data;
    }
}

class RecordModalCrudTeamsTest extends TestCase
{
    private RecordModalTeam $teamA;

    private RecordModalTeam $teamB;

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Registers the record endpoints under the `{current_team}` segment.
        $app['config']->set('kinetix.teams', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('rm_teams', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('rm_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('current_team_id');
        });

        Schema::create('team_scoped_widgets', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('team_id');
        });

        $this->teamA = RecordModalTeam::create(['name' => 'Team A']);
        $this->teamB = RecordModalTeam::create(['name' => 'Team B']);

        TeamScopedWidget::create(['name' => 'Alpha', 'team_id' => $this->teamA->id]);
        TeamScopedWidget::create(['name' => 'Bravo', 'team_id' => $this->teamB->id]);

        $this->actingAs(RecordModalTeamUser::create([
            'name'            => 'Member of A',
            'current_team_id' => $this->teamA->id,
        ]));
    }

    private function token(): string
    {
        return Table::make(TeamScopedWidget::query())
            ->recordModals(TeamScopedWidgetResource::class)
            ->toData()
            ->recordModals
            ->token;
    }

    private function teamRoute(string $name): string
    {
        return route("kinetix.tables.record.{$name}", ['current_team' => 'team-a']);
    }

    public function test_record_endpoints_are_registered_under_the_team_segment(): void
    {
        $this->assertStringEndsWith(
            '/team-a/_kinetix/tables/record',
            $this->teamRoute('store'),
        );
        $this->assertStringEndsWith(
            '/team-a/_kinetix/tables/record/resolve',
            $this->teamRoute('resolve'),
        );
    }

    public function test_resolve_returns_the_form_for_a_record_in_the_current_team(): void
    {
        $this->postJson($this->teamRoute('resolve'), [
            'token' => $this->token(),
            'mode'  => 'edit',
            'id'    => 1,
        ])
            ->assertOk()
            ->assertJsonPath('form.data.name', 'Alpha');
    }

    public function test_resolve_rejects_a_record_from_another_team(): void
    {
        $this->postJson($this->teamRoute('resolve'), [
            'token' => $this->token(),
            'mode'  => 'edit',
            'id'    => 2,
        ])->assertNotFound();
    }

    public function test_update_persists_changes_within_the_current_team(): void
    {
        $this->put($this->teamRoute('update'), [
            'token' => $this->token(),
            'id'    => 1,
            'data'  => ['name' => 'Alpha (renamed)'],
        ])->assertRedirect();

        $this->assertSame('Alpha (renamed)', TeamScopedWidget::find(1)->name);
    }

    public function test_update_cannot_touch_another_teams_record(): void
    {
        $this->putJson($this->teamRoute('update'), [
            'token' => $this->token(),
            'id'    => 2,
            'data'  => ['name' => 'Hijacked'],
        ])->assertNotFound();

        $this->assertSame('Bravo', TeamScopedWidget::find(2)->name);
    }

    public function test_destroy_cannot_delete_another_teams_record(): void
    {
        $this->deleteJson($this->teamRoute('destroy'), [
            'token' => $this->token(),
            'id'    => 2,
        ])->assertNotFound();

        $this->assertDatabaseHas('team_scoped_widgets', ['id' => 2]);
    }

    public function test_store_stamps_the_current_team_id(): void
    {
        $this->post($this->teamRoute('store'), [
            'token' => $this->token(),
            'data'  => ['name' => 'Created'],
        ])->assertRedirect();

        $this->assertDatabaseHas('team_scoped_widgets', [
            'name'    => 'Created',
            'team_id' => $this->teamA->id,
        ]);
    }
}
