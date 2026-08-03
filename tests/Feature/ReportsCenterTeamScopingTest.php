<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\ReportsCenter\ReportRun;
use Happones\Kinetix\ReportsCenter\ReportRunStatus;
use Happones\Kinetix\ReportsCenter\ReportSchedule;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Schema;

class ScopedTeam extends Model
{
    protected $table = 'teams';

    public $timestamps = false;

    protected $guarded = [];
}

class ScopedTeamUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsToMany<ScopedTeam, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(ScopedTeam::class, 'team_user', 'user_id', 'team_id');
    }
}

/**
 * The report schedules/runs tables shipped a `team_id` column that was never
 * written and never filtered, so every team saw — and could run, cancel, retry,
 * download and delete — every other team's reports.
 */
class ReportsCenterTeamScopingTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.teams', true);
        $app['config']->set('kinetix.reports_center.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        Schema::create('team_user', function (Blueprint $table) {
            $table->unsignedInteger('team_id');
            $table->unsignedInteger('user_id');
        });

        foreach ([
            __DIR__.'/../../database/migrations/2026_01_01_000020_create_kinetix_report_schedules_table.php',
            __DIR__.'/../../database/migrations/2026_01_01_000021_create_kinetix_report_runs_table.php',
        ] as $path) {
            (require $path)->up();
        }
    }

    public function test_a_schedule_query_is_constrained_to_the_active_team(): void
    {
        ReportSchedule::create(['team_id' => 1, 'report_class' => 'A', 'frequency' => 'daily']);
        ReportSchedule::create(['team_id' => 2, 'report_class' => 'B', 'frequency' => 'daily']);

        $this->actingOnTeam(1);

        $this->assertSame(['A'], ReportSchedule::query()->forCurrentTeam()->pluck('report_class')->all());

        $this->actingOnTeam(2);

        $this->assertSame(['B'], ReportSchedule::query()->forCurrentTeam()->pluck('report_class')->all());
    }

    public function test_a_run_from_another_team_is_not_reachable_by_id(): void
    {
        $foreign = ReportRun::create([
            'team_id'      => 2,
            'report_class' => 'B',
            'status'       => ReportRunStatus::Pending,
            'format'       => 'csv',
        ]);

        $this->actingOnTeam(1);

        $this->assertNull(ReportRun::query()->forCurrentTeam()->whereKey($foreign->getKey())->first());
    }

    public function test_the_scope_fails_closed_when_no_team_resolves(): void
    {
        ReportSchedule::create(['team_id' => 1, 'report_class' => 'A', 'frequency' => 'daily']);
        ReportSchedule::create(['team_id' => null, 'report_class' => 'orphan', 'frequency' => 'daily']);

        // Team scoping on, but nothing to resolve (no segment, no currentTeam):
        // the query must NOT fall back to "everything".
        $this->assertSame(
            ['orphan'],
            ReportSchedule::query()->forCurrentTeam()->pluck('report_class')->all(),
        );
    }

    public function test_the_scope_is_a_no_op_when_the_module_is_not_team_scoped(): void
    {
        config()->set('kinetix.reports_center.teams', false);

        ReportSchedule::create(['team_id' => 1, 'report_class' => 'A', 'frequency' => 'daily']);
        ReportSchedule::create(['team_id' => 2, 'report_class' => 'B', 'frequency' => 'daily']);

        $this->assertCount(2, ReportSchedule::query()->forCurrentTeam()->get());
    }

    public function test_new_rows_are_stamped_with_the_active_team(): void
    {
        $this->actingOnTeam(2);

        $this->assertSame(2, (int) ReportSchedule::currentTeamId());
        $this->assertSame(2, (int) ReportRun::currentTeamId());
    }

    public function test_no_team_id_is_stamped_in_a_single_tenant_app(): void
    {
        config()->set('kinetix.teams', false);
        config()->set('kinetix.reports_center.teams', null);

        $this->assertNull(ReportSchedule::currentTeamId());
    }

    /**
     * Authenticate a user who belongs to the team and put the team in the URL,
     * exactly as `{current_team}` does in a real request.
     */
    private function actingOnTeam(int $teamId): void
    {
        $user = ScopedTeamUser::query()->firstOrCreate(['id' => 1], ['name' => 'Member']);
        ScopedTeam::query()->firstOrCreate(['id' => $teamId], ['name' => 'Team '.$teamId]);
        $user->teams()->syncWithoutDetaching([$teamId]);

        $this->actingAs($user);

        request()->setRouteResolver(fn () => tap(
            new Route('GET', '{current_team}/anything', []),
            fn (Route $route) => $route->bind(request())->setParameter('current_team', (string) $teamId),
        ));
    }
}
