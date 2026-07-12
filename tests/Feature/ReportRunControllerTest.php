<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Exports\ExportColumn;
use Happones\Kinetix\ReportsCenter\Jobs\ReportRunJob;
use Happones\Kinetix\ReportsCenter\Report;
use Happones\Kinetix\ReportsCenter\ReportRun;
use Happones\Kinetix\ReportsCenter\ReportRunStatus;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ReportRunControllerUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class ReportRunControllerTestReport extends Report
{
    protected static ?string $model = Model::class;

    public static function getColumns(): array
    {
        return [ExportColumn::make('id')];
    }
}

class ReportRunControllerTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.reports_center.enabled', true);
        $app['config']->set('auth.providers.users.model', ReportRunControllerUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('viewKinetixReportsCenter', fn () => true);

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
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

        $this->actingAs(ReportRunControllerUser::create(['name' => 'Ada']));
    }

    public function test_launch_dispatches_a_tracked_run(): void
    {
        Queue::fake();

        $this->postJson(route('kinetix.report-runs.launch'), [
            'report' => ReportRunControllerTestReport::token(),
        ])->assertOk()->assertJson(['status' => 'queued']);

        Queue::assertPushed(ReportRunJob::class);
        $this->assertSame(1, ReportRun::query()->count());
    }

    public function test_launch_rejects_an_invalid_report_token(): void
    {
        Queue::fake();

        $this->postJson(route('kinetix.report-runs.launch'), [
            'report' => 'not-a-valid-token',
        ])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_cancel_marks_a_cancellable_run_cancelled(): void
    {
        $run = ReportRun::create([
            'report_class' => ReportRunControllerTestReport::class,
            'status'       => ReportRunStatus::Running,
            'format'       => 'csv',
        ]);

        $this->postJson(route('kinetix.report-runs.cancel', $run))
            ->assertOk()
            ->assertJson(['status' => 'cancelled']);

        $this->assertSame(ReportRunStatus::Cancelled, $run->fresh()->status);
    }

    public function test_cancel_rejects_a_run_that_is_no_longer_cancellable(): void
    {
        $run = ReportRun::create([
            'report_class' => ReportRunControllerTestReport::class,
            'status'       => ReportRunStatus::Completed,
            'format'       => 'csv',
        ]);

        $this->postJson(route('kinetix.report-runs.cancel', $run))->assertStatus(422);

        $this->assertSame(ReportRunStatus::Completed, $run->fresh()->status);
    }

    public function test_retry_dispatches_a_fresh_run_for_a_retryable_one(): void
    {
        Queue::fake();

        $run = ReportRun::create([
            'report_class' => ReportRunControllerTestReport::class,
            'status'       => ReportRunStatus::Failed,
            'format'       => 'csv',
            'parameters'   => ['foo' => 'bar'],
        ]);

        $this->postJson(route('kinetix.report-runs.retry', $run))
            ->assertOk()
            ->assertJson(['status' => 'queued']);

        Queue::assertPushed(ReportRunJob::class);
        $this->assertSame(2, ReportRun::query()->count());
    }

    public function test_retry_rejects_a_run_that_is_not_retryable(): void
    {
        Queue::fake();

        $run = ReportRun::create([
            'report_class' => ReportRunControllerTestReport::class,
            'status'       => ReportRunStatus::Running,
            'format'       => 'csv',
        ]);

        $this->postJson(route('kinetix.report-runs.retry', $run))->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_download_streams_a_completed_runs_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('kinetix-report-runs/file.csv', "id\n1");

        $run = ReportRun::create([
            'report_class' => ReportRunControllerTestReport::class,
            'status'       => ReportRunStatus::Completed,
            'format'       => 'csv',
            'disk'         => 'public',
            'path'         => 'kinetix-report-runs/file.csv',
            'file_name'    => 'report.csv',
            'expires_at'   => now()->addDay(),
        ]);

        $this->get(route('kinetix.report-runs.download', $run))->assertOk();
    }

    public function test_download_404s_once_expired(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('kinetix-report-runs/file.csv', "id\n1");

        $run = ReportRun::create([
            'report_class' => ReportRunControllerTestReport::class,
            'status'       => ReportRunStatus::Completed,
            'format'       => 'csv',
            'disk'         => 'public',
            'path'         => 'kinetix-report-runs/file.csv',
            'file_name'    => 'report.csv',
            'expires_at'   => now()->subDay(),
        ]);

        $this->get(route('kinetix.report-runs.download', $run))->assertNotFound();
    }
}
