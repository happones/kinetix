<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Exports\ExportColumn;
use Happones\Kinetix\Exports\RelationScope;
use Happones\Kinetix\ReportsCenter\Jobs\ReportRunJob;
use Happones\Kinetix\ReportsCenter\KinetixReportsCenter;
use Happones\Kinetix\ReportsCenter\Report;
use Happones\Kinetix\ReportsCenter\ReportRun;
use Happones\Kinetix\ReportsCenter\ReportRunStatus;
use Happones\Kinetix\ReportsCenter\ReportSchedule;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class RcsUser extends AuthUser
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class RcsProject extends Model
{
    protected $table = 'rcs_projects';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return HasMany<RcsItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(RcsItem::class, 'project_id');
    }
}

class RcsItem extends Model
{
    protected $table = 'rcs_items';

    public $timestamps = false;

    protected $guarded = [];
}

class RcsItemsReport extends Report
{
    protected static ?string $model = RcsItem::class;

    public static function getColumns(): array
    {
        return [ExportColumn::make('id'), ExportColumn::make('name')];
    }
}

class RcsDeniedReport extends Report
{
    protected static ?string $model = RcsItem::class;

    public static function getColumns(): array
    {
        return [ExportColumn::make('id')];
    }

    public function authorize(?Authenticatable $user): bool
    {
        return false;
    }
}

/** Only Ada may run it: lets a test take access away from one user. */
class RcsAdaOnlyReport extends Report
{
    protected static ?string $model = RcsItem::class;

    public static function getColumns(): array
    {
        return [ExportColumn::make('id')];
    }

    public function authorize(?Authenticatable $user): bool
    {
        return $user?->getAttribute('name') === 'Ada';
    }
}

/**
 * The Reports Center never lets a client name a relation (or an id list) the
 * export runs through: client parameters lose the reserved keys, a relation
 * scope only opens from a server-made seal, and each report's own
 * `authorize()` guards launch, schedule, run-now, retry and the type list.
 */
class ReportsCenterSecurityTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.reports_center.enabled', true);
        $app['config']->set('auth.providers.users.model', RcsUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('viewKinetixReportsCenter', fn () => true);

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('rcs_projects', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('rcs_items', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('project_id')->nullable();
            $table->string('name');
        });

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

        Storage::fake('local');
        config()->set('kinetix.filesystem.disk', 'public');
        Queue::fake();

        RcsItem::create(['name' => 'Alpha']);
        RcsItem::create(['name' => 'Beta']);
    }

    /**
     * @return array<string, mixed>
     */
    private function deleteVictim(RcsUser $victim): array
    {
        return ['relation' => ['parent' => RcsUser::class, 'key' => $victim->getKey(), 'name' => 'delete']];
    }

    private function runJob(ReportRun $run): ReportRun
    {
        (new ReportRunJob($run->id))->handle();

        return $run->refresh();
    }

    public function test_a_client_named_relation_on_launch_never_calls_the_method(): void
    {
        $attacker = RcsUser::create(['name' => 'Mallory']);
        $victim   = RcsUser::create(['name' => 'Victim']);

        $response = $this->actingAs($attacker)->postJson(route('kinetix.report-runs.launch'), [
            'report'     => RcsItemsReport::token(),
            'parameters' => [...$this->deleteVictim($victim), 'ids' => [999], 'region' => 'eu'],
        ])->assertOk();

        $run = ReportRun::query()->findOrFail($response->json('run_id'));

        // The reserved keys never reach the run; the report's own keys do.
        $this->assertSame(['region' => 'eu'], $run->parameters);

        $run = $this->runJob($run);

        $this->assertNotNull(RcsUser::query()->find($victim->getKey()));
        $this->assertSame(ReportRunStatus::Completed, $run->status);
        $this->assertSame(2, $run->processed_rows);
    }

    public function test_a_poisoned_run_row_exports_nothing_and_calls_nothing(): void
    {
        $victim = RcsUser::create(['name' => 'Victim']);

        $run = ReportRun::create([
            'report_class' => RcsItemsReport::class,
            'status'       => ReportRunStatus::Pending,
            'format'       => 'csv',
            'parameters'   => $this->deleteVictim($victim),
        ]);

        $run = $this->runJob($run);

        $this->assertNotNull(RcsUser::query()->find($victim->getKey()));
        $this->assertSame(0, $run->processed_rows);
    }

    public function test_a_poisoned_stored_schedule_is_inert_through_run_now_and_the_command(): void
    {
        $owner  = RcsUser::create(['name' => 'Owner']);
        $victim = RcsUser::create(['name' => 'Victim']);

        $schedule = ReportSchedule::create([
            'report_class'  => RcsItemsReport::class,
            'frequency'     => 'daily',
            'enabled'       => true,
            'parameters'    => $this->deleteVictim($victim),
            'next_run_at'   => now()->subMinute(),
            'created_by_id' => $owner->getKey(),
        ]);

        $runId = $this->actingAs($owner)
            ->postJson(route('kinetix.report-schedules.run-now', $schedule))
            ->assertOk()
            ->json('run_id');

        $this->artisan('kinetix:report-schedules:dispatch-due')->assertSuccessful();

        $runs = ReportRun::query()->get();
        $this->assertCount(2, $runs);
        $this->assertContains($runId, $runs->pluck('id')->all());

        foreach ($runs as $run) {
            $this->assertSame(0, $this->runJob($run)->processed_rows);
        }

        $this->assertNotNull(RcsUser::query()->find($victim->getKey()));
    }

    public function test_a_poisoned_run_retried_is_inert(): void
    {
        $owner  = RcsUser::create(['name' => 'Owner']);
        $victim = RcsUser::create(['name' => 'Victim']);

        $failed = ReportRun::create([
            'report_class'   => RcsItemsReport::class,
            'status'         => ReportRunStatus::Failed,
            'format'         => 'csv',
            'parameters'     => $this->deleteVictim($victim),
            'launched_by_id' => $owner->getKey(),
        ]);

        $runId = $this->actingAs($owner)
            ->postJson(route('kinetix.report-runs.retry', $failed))
            ->assertOk()
            ->json('run_id');

        $this->assertSame(0, $this->runJob(ReportRun::query()->findOrFail($runId))->processed_rows);
        $this->assertNotNull(RcsUser::query()->find($victim->getKey()));
    }

    public function test_schedule_store_and_update_drop_the_reserved_parameter_keys(): void
    {
        $owner  = RcsUser::create(['name' => 'Owner']);
        $victim = RcsUser::create(['name' => 'Victim']);

        $id = $this->actingAs($owner)->postJson(route('kinetix.report-schedules.store'), [
            'report'     => RcsItemsReport::token(),
            'frequency'  => 'daily',
            'parameters' => [...$this->deleteVictim($victim), 'ids' => [1], 'region' => 'eu'],
        ])->assertOk()->json('id');

        $schedule = ReportSchedule::query()->findOrFail($id);
        $this->assertSame(['region' => 'eu'], $schedule->parameters);

        $this->putJson(route('kinetix.report-schedules.update', $schedule), [
            'frequency'  => 'weekly',
            'parameters' => [...$this->deleteVictim($victim), 'region' => 'us'],
        ])->assertOk();

        $this->assertSame(['region' => 'us'], $schedule->refresh()->parameters);
    }

    public function test_a_plain_array_relation_set_by_code_exports_nothing(): void
    {
        $project = RcsProject::create(['name' => 'Kinetix']);
        $project->items()->create(['name' => 'Scoped']);

        $count = (new RcsItemsReport)
            ->withParameters(['relation' => ['parent' => RcsProject::class, 'key' => $project->getKey(), 'name' => 'items']])
            ->resolveExportQuery()
            ->count();

        $this->assertSame(0, $count);
    }

    public function test_a_forged_or_foreign_purpose_seal_exports_nothing(): void
    {
        $project = RcsProject::create(['name' => 'Kinetix']);
        $project->items()->create(['name' => 'Scoped']);

        $foreign = Crypt::encrypt([
            'purpose'  => 'kinetix-relation-manager',
            'exporter' => RcsItemsReport::class,
            'parent'   => RcsProject::class,
            'key'      => $project->getKey(),
            'name'     => 'items',
        ]);

        $descriptorShape = Crypt::encrypt([
            'parent'   => RcsProject::class,
            'key'      => $project->getKey(),
            'relation' => 'items',
        ]);

        foreach (['forged-not-encrypted', $foreign, $descriptorShape] as $relation) {
            $this->assertSame(0, (new RcsItemsReport)
                ->withParameters(['relation' => $relation])
                ->resolveExportQuery()
                ->count());
        }
    }

    public function test_a_seal_opens_only_for_the_exporter_it_was_made_for(): void
    {
        $project = RcsProject::create(['name' => 'Kinetix']);
        $project->items()->create(['name' => 'Scoped']);

        $sealed = RelationScope::seal($project, 'items', RcsItemsReport::class);

        $this->assertSame(['Scoped'], (new RcsItemsReport)
            ->withParameters(['relation' => $sealed])
            ->resolveExportQuery()
            ->pluck('name')
            ->all());

        $this->assertSame(0, (new RcsDeniedReport)
            ->withParameters(['relation' => $sealed])
            ->resolveExportQuery()
            ->count());

        $this->assertNull(RelationScope::open($sealed, RcsDeniedReport::class));
    }

    public function test_a_seal_naming_a_non_relation_method_exports_nothing_and_calls_nothing(): void
    {
        $victim = RcsUser::create(['name' => 'Victim']);

        $sealed = RelationScope::seal($victim, 'delete', RcsItemsReport::class);

        $this->assertSame(0, (new RcsItemsReport)
            ->withParameters(['relation' => $sealed])
            ->resolveExportQuery()
            ->count());

        $this->assertNotNull(RcsUser::query()->find($victim->getKey()));
    }

    public function test_a_denied_user_cannot_launch_schedule_run_now_or_retry_and_does_not_see_the_type(): void
    {
        KinetixReportsCenter::register(RcsItemsReport::class);
        KinetixReportsCenter::register(RcsDeniedReport::class);

        $user = RcsUser::create(['name' => 'Ada']);
        $this->actingAs($user);

        $this->postJson(route('kinetix.report-runs.launch'), [
            'report' => RcsDeniedReport::token(),
        ])->assertForbidden();

        $this->postJson(route('kinetix.report-schedules.store'), [
            'report'    => RcsDeniedReport::token(),
            'frequency' => 'daily',
        ])->assertForbidden();

        $schedule = ReportSchedule::create([
            'report_class'  => RcsDeniedReport::class,
            'frequency'     => 'daily',
            'created_by_id' => $user->getKey(),
        ]);

        $this->postJson(route('kinetix.report-schedules.run-now', $schedule))->assertForbidden();

        $failed = ReportRun::create([
            'report_class'   => RcsDeniedReport::class,
            'status'         => ReportRunStatus::Failed,
            'format'         => 'csv',
            'launched_by_id' => $user->getKey(),
        ]);

        $this->postJson(route('kinetix.report-runs.retry', $failed))->assertForbidden();

        $this->assertSame(0, ReportSchedule::query()->where('report_class', RcsDeniedReport::class)->where('id', '!=', $schedule->id)->count());
        $this->assertSame(1, ReportRun::query()->count());
        Queue::assertNothingPushed();

        $labels = collect($this->getJson(route('kinetix.report-types.index'))->assertOk()->json())->pluck('label')->all();

        $this->assertContains((new RcsItemsReport)->label(), $labels);
        $this->assertNotContains((new RcsDeniedReport)->label(), $labels);
    }

    public function test_the_command_skips_a_schedule_whose_creator_may_no_longer_run_it(): void
    {
        $user = RcsUser::create(['name' => 'Ada']);

        $schedule = ReportSchedule::create([
            'report_class'  => RcsDeniedReport::class,
            'frequency'     => 'daily',
            'enabled'       => true,
            'next_run_at'   => now()->subMinute(),
            'created_by_id' => $user->getKey(),
        ]);

        $this->artisan('kinetix:report-schedules:dispatch-due')->assertSuccessful();

        $this->assertSame(0, ReportRun::query()->count());
        Queue::assertNothingPushed();
        $this->assertTrue($schedule->refresh()->next_run_at->isFuture());
    }

    public function test_runs_of_a_report_the_viewer_may_not_run_are_hidden_and_closed_to_them(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('kinetix-report-runs/file.csv', "id\n1");

        $this->actingAs(RcsUser::create(['name' => 'Bob']));

        $completed = fn (string $class): ReportRun => ReportRun::create([
            'report_class' => $class,
            'status'       => ReportRunStatus::Completed,
            'format'       => 'csv',
            'disk'         => 'public',
            'path'         => 'kinetix-report-runs/file.csv',
            'file_name'    => 'report.csv',
            'expires_at'   => now()->addDay(),
        ]);

        $allowed = $completed(RcsItemsReport::class);
        $denied  = $completed(RcsDeniedReport::class);
        $pending = ReportRun::create(['report_class' => RcsDeniedReport::class, 'status' => ReportRunStatus::Pending, 'format' => 'csv']);
        ReportRun::create(['report_class' => 'App\\Gone\\RemovedReport', 'status' => ReportRunStatus::Completed, 'format' => 'csv']);

        $ids = collect($this->getJson(route('kinetix.report-runs.index'))->assertOk()->json('records'))->pluck('id')->all();
        $this->assertSame([(string) $allowed->id], array_map('strval', $ids));

        $this->get(route('kinetix.report-runs.download', $allowed))->assertOk();
        $this->get(route('kinetix.report-runs.download', $denied))->assertForbidden();
        $this->postJson(route('kinetix.report-runs.cancel', $pending))->assertForbidden();

        $this->assertSame(ReportRunStatus::Pending, $pending->refresh()->status);
    }

    public function test_run_now_needs_the_creator_to_still_be_allowed(): void
    {
        $ada = RcsUser::create(['name' => 'Ada']);
        $bob = RcsUser::create(['name' => 'Bob']);

        $schedule = fn (RcsUser $creator): ReportSchedule => ReportSchedule::create([
            'report_class'  => RcsAdaOnlyReport::class,
            'frequency'     => 'daily',
            'created_by_id' => $creator->getKey(),
        ]);

        // Ada may run it, but the run would be launched as (and sent to) Bob.
        $this->actingAs($ada)->postJson(route('kinetix.report-schedules.run-now', $schedule($bob)))->assertForbidden();
        $this->actingAs($ada)->postJson(route('kinetix.report-schedules.run-now', $schedule($ada)))->assertOk();

        $this->assertSame(1, ReportRun::query()->count());
    }

    public function test_retry_needs_the_original_launcher_to_still_be_allowed(): void
    {
        $ada = RcsUser::create(['name' => 'Ada']);
        $bob = RcsUser::create(['name' => 'Bob']);

        $failed = fn (RcsUser $launcher): ReportRun => ReportRun::create([
            'report_class'   => RcsAdaOnlyReport::class,
            'status'         => ReportRunStatus::Failed,
            'format'         => 'csv',
            'launched_by_id' => $launcher->getKey(),
        ]);

        $this->actingAs($ada)->postJson(route('kinetix.report-runs.retry', $failed($bob)))->assertForbidden();
        $this->actingAs($ada)->postJson(route('kinetix.report-runs.retry', $failed($ada)))->assertOk();

        $this->assertSame(3, ReportRun::query()->count());
    }
}
