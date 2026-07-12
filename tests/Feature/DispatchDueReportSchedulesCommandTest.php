<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Exports\ExportColumn;
use Happones\Kinetix\ReportsCenter\Jobs\ReportRunJob;
use Happones\Kinetix\ReportsCenter\Report;
use Happones\Kinetix\ReportsCenter\ReportSchedule;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

class DispatchDueScheduleTestReport extends Report
{
    protected static ?string $model = Model::class;

    public static function getColumns(): array
    {
        return [ExportColumn::make('id')];
    }
}

class DispatchDueReportSchedulesCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
    }

    public function test_dispatches_due_schedules_and_advances_next_run_at(): void
    {
        Queue::fake();

        $schedule = ReportSchedule::create([
            'report_class' => DispatchDueScheduleTestReport::class,
            'frequency'    => 'daily',
            'enabled'      => true,
            'next_run_at'  => now()->subMinute(),
        ]);

        $this->artisan('kinetix:report-schedules:dispatch-due')->assertSuccessful();

        Queue::assertPushed(ReportRunJob::class);

        $schedule->refresh();
        $this->assertNotNull($schedule->last_run_at);
        $this->assertTrue($schedule->next_run_at->isFuture());
        $this->assertTrue($schedule->enabled);
    }

    public function test_skips_schedules_not_yet_due(): void
    {
        Queue::fake();

        ReportSchedule::create([
            'report_class' => DispatchDueScheduleTestReport::class,
            'frequency'    => 'daily',
            'enabled'      => true,
            'next_run_at'  => now()->addDay(),
        ]);

        $this->artisan('kinetix:report-schedules:dispatch-due')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_skips_disabled_schedules(): void
    {
        Queue::fake();

        ReportSchedule::create([
            'report_class' => DispatchDueScheduleTestReport::class,
            'frequency'    => 'daily',
            'enabled'      => false,
            'next_run_at'  => now()->subMinute(),
        ]);

        $this->artisan('kinetix:report-schedules:dispatch-due')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_once_frequency_disables_itself_after_firing(): void
    {
        Queue::fake();

        $schedule = ReportSchedule::create([
            'report_class' => DispatchDueScheduleTestReport::class,
            'frequency'    => 'once',
            'enabled'      => true,
            'next_run_at'  => now()->subMinute(),
        ]);

        $this->artisan('kinetix:report-schedules:dispatch-due')->assertSuccessful();

        $schedule->refresh();
        $this->assertNull($schedule->next_run_at);
        $this->assertFalse($schedule->enabled);
    }

    public function test_a_schedule_with_no_next_run_at_yet_is_treated_as_due(): void
    {
        Queue::fake();

        ReportSchedule::create([
            'report_class' => DispatchDueScheduleTestReport::class,
            'frequency'    => 'weekly',
            'enabled'      => true,
            'next_run_at'  => null,
        ]);

        $this->artisan('kinetix:report-schedules:dispatch-due')->assertSuccessful();

        Queue::assertPushed(ReportRunJob::class);
    }
}
