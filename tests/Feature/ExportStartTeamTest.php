<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Exports\ExportColumn;
use Happones\Kinetix\Exports\Exporter;
use Happones\Kinetix\Exports\Jobs\ExportProcessor;
use Happones\Kinetix\Support\DescriptorAudience;
use Happones\Kinetix\Support\KinetixTeams;
use Happones\Kinetix\Support\SignedDescriptor;
use Happones\Kinetix\Tests\Concerns\CreatesPermissionTables;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;

class EstTeam extends Model
{
    protected $table = 'est_teams';

    public $timestamps = false;

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

class EstUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $guard_name = 'web';

    /** @return BelongsTo<EstTeam, $this> */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(EstTeam::class, 'current_team_id');
    }

    /** @return BelongsToMany<EstTeam, $this> */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(EstTeam::class, 'est_team_user', 'user_id', 'team_id');
    }
}

class EstRecord extends Model
{
    protected $table = 'est_records';

    public $timestamps = false;

    protected $guarded = [];
}

/** Exporting takes the `exporter` role, held per team. */
class EstRecordPolicy
{
    public function viewAny(EstUser $user): bool
    {
        return $user->hasRole('exporter');
    }
}

class EstExporter extends Exporter
{
    protected static ?string $model = EstRecord::class;

    public static function getColumns(): array
    {
        return [ExportColumn::make('name')];
    }

    public function context(Request $request): array
    {
        return ['team' => KinetixTeams::currentTeamKey($request)];
    }
}

/**
 * The export endpoint isn't team-prefixed: it must act in the team the
 * export button was rendered in (the token's team claim), with a membership
 * check and authorization in THAT team, never in the user's stored team.
 */
class ExportStartTeamTest extends TestCase
{
    use CreatesPermissionTables;

    private EstTeam $teamA;

    private EstTeam $teamB;

    /**
     * @param  Application        $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
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
        $app['config']->set('permission.teams', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPermissionTables();
        $migration = require __DIR__.'/../../database/migrations/2026_01_01_000017_add_kinetix_team_fields_to_permission_tables.php';
        $migration->up();

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('current_team_id')->nullable();
        });

        Schema::create('est_teams', function (Blueprint $table) {
            $table->increments('id');
            $table->string('slug')->unique();
        });

        Schema::create('est_team_user', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('team_id');
        });

        Schema::create('est_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        (new \ReflectionProperty(Model::class, 'guardableColumns'))->setValue(null, []);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        Gate::policy(EstRecord::class, EstRecordPolicy::class);

        $this->teamA = EstTeam::create(['slug' => 'a']);
        $this->teamB = EstTeam::create(['slug' => 'b']);
    }

    public function test_an_export_started_from_team_b_runs_in_team_b_not_the_stored_team(): void
    {
        Queue::fake();
        $user = $this->member(exporterIn: [$this->teamA, $this->teamB]);

        $this->actingAs($user)
            ->postJson(route('kinetix.exports.start'), ['exporter' => $this->tokenMintedIn('b', $user)])
            ->assertOk();

        Queue::assertPushed(ExportProcessor::class, function (ExportProcessor $job): bool {
            return $this->jobField($job, 'teamKey') === $this->teamB->getKey()
                && $this->jobField($job, 'context') === ['team' => $this->teamB->getKey()];
        });
    }

    public function test_a_role_held_only_in_the_stored_team_does_not_authorize_an_export_from_another_team(): void
    {
        Queue::fake();
        $user = $this->member(exporterIn: [$this->teamA]);

        $this->actingAs($user)
            ->postJson(route('kinetix.exports.start'), ['exporter' => $this->tokenMintedIn('b', $user)])
            ->assertForbidden()
            ->assertJson(['message' => __('kinetix.export_forbidden')]);

        Queue::assertNothingPushed();
    }

    public function test_a_token_minted_in_a_team_the_user_has_left_is_refused(): void
    {
        Queue::fake();
        $user  = $this->member(exporterIn: [$this->teamA]);
        $token = $this->tokenMintedIn('a', $user);

        $user->teams()->detach($this->teamA->getKey());
        KinetixTeams::forgetCurrentTeam();

        $this->actingAs($user)
            ->postJson(route('kinetix.exports.start'), ['exporter' => $token])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_a_token_minted_without_a_team_uses_the_stored_team(): void
    {
        Queue::fake();
        $user = $this->member(exporterIn: [$this->teamA]);

        $this->actingAs($user)
            ->postJson(route('kinetix.exports.start'), ['exporter' => $this->tokenMintedIn(null, $user, storedTeam: false)])
            ->assertOk();

        Queue::assertPushed(ExportProcessor::class, fn (ExportProcessor $job): bool => $this->jobField($job, 'context') === ['team' => $this->teamA->getKey()]);
    }

    public function test_a_relation_descriptor_from_another_team_is_refused(): void
    {
        Queue::fake();
        $user     = $this->member(exporterIn: [$this->teamA, $this->teamB]);
        $relation = $this->mintIn('a', $user, static fn (): string => SignedDescriptor::seal([
            'parent'   => EstTeam::class,
            'key'      => 1,
            'relation' => 'records',
        ], DescriptorAudience::RelationManager));

        $this->actingAs($user)
            ->postJson(route('kinetix.exports.start'), [
                'exporter' => $this->tokenMintedIn('b', $user),
                'relation' => $relation,
            ])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_the_spatie_team_id_is_restored_after_the_request(): void
    {
        Queue::fake();
        $user = $this->member(exporterIn: [$this->teamB]);

        $before = app(PermissionRegistrar::class)->getPermissionsTeamId();

        $this->actingAs($user)
            ->postJson(route('kinetix.exports.start'), ['exporter' => $this->tokenMintedIn('b', $user)])
            ->assertOk();

        $this->assertSame($before, app(PermissionRegistrar::class)->getPermissionsTeamId());
    }

    public function test_with_kinetix_teams_off_the_hosts_own_spatie_team_is_left_alone(): void
    {
        config()->set('kinetix.teams', false);
        Queue::fake();

        $user      = EstUser::create(['name' => 'Host']);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($this->teamB->getKey());
        Role::findOrCreate('exporter', 'web');
        $user->assignRole('exporter');
        $user->unsetRelation('roles');

        // The host's own middleware pinned team B for this request.
        $this->actingAs($user)
            ->postJson(route('kinetix.exports.start'), ['exporter' => EstExporter::token()])
            ->assertOk();

        Queue::assertPushed(ExportProcessor::class);
        $this->assertSame($this->teamB->getKey(), $registrar->getPermissionsTeamId());
    }

    /**
     * A member of both teams, stored on team A, holding the `exporter` role
     * in the given teams.
     *
     * @param list<EstTeam> $exporterIn
     */
    private function member(array $exporterIn): EstUser
    {
        $user = EstUser::create(['name' => 'Member', 'current_team_id' => $this->teamA->getKey()]);
        $user->teams()->attach([$this->teamA->getKey(), $this->teamB->getKey()]);

        $registrar = app(PermissionRegistrar::class);

        foreach ($exporterIn as $team) {
            $registrar->setPermissionsTeamId($team->getKey());
            Role::findOrCreate('exporter', 'web');
            $user->assignRole('exporter');
            $user->unsetRelation('roles');
        }

        $registrar->setPermissionsTeamId(null);

        return $user;
    }

    /** An exporter token minted on a page routed to `/{slug}/...` (or with no team). */
    private function tokenMintedIn(?string $slug, EstUser $user, bool $storedTeam = true): string
    {
        if (! $storedTeam) {
            $user->forceFill(['current_team_id' => null])->save();
        }

        $token = $this->mintIn($slug, $user, static fn (): string => EstExporter::token());

        if (! $storedTeam) {
            $user->forceFill(['current_team_id' => $this->teamA->getKey()])->save();
            $user->unsetRelation('currentTeam');
        }

        return $token;
    }

    /**
     * Run `$mint` inside a request routed to `/{slug}/...`.
     *
     * @param callable(): string $mint
     */
    private function mintIn(?string $slug, EstUser $user, callable $mint): string
    {
        $previous = $this->app['request'];
        $request  = Request::create('/');
        $route    = new RoutingRoute('GET', '{current_team}/page', static fn () => null);
        $route->bind($request);

        if ($slug !== null) {
            $route->setParameter('current_team', $slug);
        }

        $request->setRouteResolver(static fn () => $route);
        $request->setUserResolver(static fn () => $user);
        $this->app->instance('request', $request);
        $this->actingAs($user);

        try {
            return $mint();
        } finally {
            $this->app->instance('request', $previous);
        }
    }

    private function jobField(ExportProcessor $job, string $field): mixed
    {
        return (new \ReflectionProperty($job, $field))->getValue($job);
    }
}
