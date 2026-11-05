<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Exports\ExportColumn;
use Happones\Kinetix\ReportsCenter\Report;
use Happones\Kinetix\ReportsCenter\ReportRun;
use Happones\Kinetix\ReportsCenter\ReportSchedule;
use Happones\Kinetix\Tests\Concerns\CreatesPermissionTables;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;

class TeamRoleScheduleUser extends AuthUser
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $guard_name = 'web';
}

class TeamRoleScheduleItem extends Model
{
    protected $table = 'team_role_schedule_items';

    public $timestamps = false;

    protected $guarded = [];
}

class TeamRoleScheduleReport extends Report
{
    protected static ?string $model = TeamRoleScheduleItem::class;

    public static function getColumns(): array
    {
        return [ExportColumn::make('id')];
    }

    // What a permission-based policy does: `$user->can('items.export')`.
    public function authorize(?Authenticatable $user): bool
    {
        return $user !== null && $user->can('items.export');
    }
}

/**
 * The console scheduler has no request team: a schedule's creator is judged
 * inside the schedule's own team, so a role they hold there counts.
 */
class ReportScheduleTeamRoleTest extends TestCase
{
    use CreatesPermissionTables;

    /**
     * @param  Application              $app
     * @return array<int, class-string>
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
        $app['config']->set('kinetix.reports_center.enabled', true);
        $app['config']->set('kinetix.permissions.enabled', true);
        $app['config']->set('kinetix.permissions.teams', true);
        $app['config']->set('permission.teams', true);
        $app['config']->set('auth.providers.users.model', TeamRoleScheduleUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPermissionTables();
        $migration = require __DIR__.'/../../database/migrations/2026_01_01_000017_add_kinetix_team_fields_to_permission_tables.php';
        $migration->up();
        (new \ReflectionProperty(Model::class, 'guardableColumns'))->setValue(null, []);

        Schema::create('team_role_schedule_items', fn (Blueprint $t) => $t->increments('id'));
        Schema::create('kinetix_report_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->unsignedBigInteger('report_schedule_id')->nullable();
            $table->string('report_class');
            $table->string('status')->default('pending');
            $table->string('format');
            $table->unsignedBigInteger('processed_rows')->default(0);
            $table->unsignedBigInteger('total_rows')->nullable();
            $table->unsignedTinyInteger('percent')->nullable();
            $table->json('parameters')->nullable();
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('file_name')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('launched_by_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
        Schema::create('kinetix_report_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('report_class');
            $table->string('frequency');
            $table->json('parameters')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->boolean('notify_on_completion')->default(false);
            $table->timestamps();
        });
        Queue::fake();
    }

    private function exporterInTeam(int $teamId): TeamRoleScheduleUser
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();
        Permission::findOrCreate('items.export', 'web');

        $user = TeamRoleScheduleUser::create(['name' => 'Ada']);
        $registrar->setPermissionsTeamId($teamId);
        Role::create(['name' => 'exporter', 'guard_name' => 'web', 'team_id' => $teamId])->givePermissionTo('items.export');
        $user->assignRole('exporter');
        $this->assertTrue($user->fresh()->can('items.export'), "sanity: allowed inside team {$teamId}");
        $registrar->setPermissionsTeamId(null);

        return $user;
    }

    private function dueSchedule(int $teamId, TeamRoleScheduleUser $creator): ReportSchedule
    {
        return ReportSchedule::create([
            'team_id'       => $teamId,
            'report_class'  => TeamRoleScheduleReport::class,
            'frequency'     => 'daily',
            'enabled'       => true,
            'next_run_at'   => now()->subMinute(),
            'created_by_id' => $creator->getKey(),
        ]);
    }

    public function test_a_team_role_holder_schedule_fires(): void
    {
        $user = $this->exporterInTeam(7);
        $this->dueSchedule(7, $user);

        $this->artisan('kinetix:report-schedules:dispatch-due')->assertSuccessful();

        $this->assertSame(1, ReportRun::query()->count(), 'the schedule of a team-7 role holder was skipped');
        $this->assertNull(app(PermissionRegistrar::class)->getPermissionsTeamId());
    }

    public function test_a_role_in_another_team_does_not_fire_the_schedule(): void
    {
        $user = $this->exporterInTeam(7);
        $this->dueSchedule(8, $user);

        $this->artisan('kinetix:report-schedules:dispatch-due')->assertSuccessful();

        $this->assertSame(0, ReportRun::query()->count());
    }
}
