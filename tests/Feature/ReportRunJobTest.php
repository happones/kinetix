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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ReportRunTestItem extends Model
{
    protected $table = 'report_run_test_items';

    public $timestamps = false;

    protected $guarded = [];
}

class ReportRunItemsReport extends Report
{
    protected static ?string $model = ReportRunTestItem::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id'),
            ExportColumn::make('name'),
        ];
    }

    // Force one row per chunk so the per-chunk cancellation probe fires
    // repeatedly (deterministic for the mid-run cancellation test below).
    public function chunkSize(): int
    {
        return 1;
    }
}

class ReportRunJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('report_run_test_items', function (Blueprint $table) {
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

        Storage::fake('local');
        config()->set('kinetix.filesystem.disk', 'public');
        config()->set('kinetix.reports_center.retention_days', 7);
    }

    public function test_happy_path_writes_the_file_and_completes(): void
    {
        ReportRunTestItem::create(['name' => 'Ada']);
        ReportRunTestItem::create(['name' => 'Bob']);

        $run = ReportRun::create([
            'report_class' => ReportRunItemsReport::class,
            'status'       => ReportRunStatus::Pending,
            'format'       => 'csv',
        ]);

        (new ReportRunJob($run->id))->handle();

        $run->refresh();

        $this->assertSame(ReportRunStatus::Completed, $run->status);
        $this->assertSame(2, $run->processed_rows);
        $this->assertSame(100, $run->percent);
        $this->assertNotNull($run->path);
        $this->assertNotNull($run->expires_at);
        $this->assertTrue($run->expires_at->isFuture());

        $contents = Storage::disk('local')->get($run->path);
        $this->assertStringContainsString('Ada', $contents);
        $this->assertStringContainsString('Bob', $contents);
    }

    public function test_cancelling_mid_chunk_halts_processing_and_marks_cancelled(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            ReportRunTestItem::create(['name' => "Item {$i}"]);
        }

        $run = ReportRun::create([
            'report_class' => ReportRunItemsReport::class,
            'status'       => ReportRunStatus::Pending,
            'format'       => 'csv',
        ]);

        // The job's cancellation probe is a raw SELECT of `status` against
        // this table — one fires at the top of handle(), then one per chunk.
        // Cancelling right after the 2nd such probe (the first per-chunk
        // check) means the 3rd probe (chunk 2's check) sees "cancelled".
        $statusChecks = 0;
        DB::listen(function ($query) use (&$statusChecks, $run): void {
            $sql = strtolower($query->sql);

            if (
                str_starts_with($sql, 'select')
                && str_contains($sql, 'kinetix_report_runs')
                && str_contains($sql, 'status')
            ) {
                $statusChecks++;

                if ($statusChecks === 2) {
                    DB::table('kinetix_report_runs')
                        ->where('id', $run->id)
                        ->update(['status' => ReportRunStatus::Cancelled->value]);
                }
            }
        });

        (new ReportRunJob($run->id))->handle();

        $run->refresh();

        $this->assertSame(ReportRunStatus::Cancelled, $run->status);
        $this->assertLessThan(5, $run->processed_rows);
        $this->assertNotNull($run->cancelled_at);
    }

    public function test_cancelled_before_pickup_is_a_clean_noop(): void
    {
        $run = ReportRun::create([
            'report_class' => ReportRunItemsReport::class,
            'status'       => ReportRunStatus::Cancelled,
            'format'       => 'csv',
        ]);

        (new ReportRunJob($run->id))->handle();

        $run->refresh();

        $this->assertSame(ReportRunStatus::Cancelled, $run->status);
        $this->assertNull($run->started_at);
        $this->assertNull($run->path);
    }

    public function test_handle_rethrows_without_marking_the_row_failed_itself(): void
    {
        $run = ReportRun::create([
            'report_class' => 'Totally\\Unknown\\ReportClass',
            'status'       => ReportRunStatus::Pending,
            'format'       => 'csv',
        ]);

        $job = new ReportRunJob($run->id);

        try {
            $job->handle();
            $this->fail('Expected handle() to throw.');
        } catch (RuntimeException) {
            // Expected — handle()'s own catch re-throws so Laravel's
            // $tries/backoff can still retry a transient error.
        }

        $run->refresh();

        // NOT Failed — only the failed() lifecycle hook writes that terminal
        // state, once retries are exhausted.
        $this->assertSame(ReportRunStatus::Running, $run->status);
    }

    public function test_failed_hook_marks_the_run_failed_with_the_error_message(): void
    {
        $run = ReportRun::create([
            'report_class' => ReportRunItemsReport::class,
            'status'       => ReportRunStatus::Running,
            'format'       => 'csv',
        ]);

        (new ReportRunJob($run->id))->failed(new RuntimeException('boom'));

        $run->refresh();

        $this->assertSame(ReportRunStatus::Failed, $run->status);
        $this->assertSame('boom', $run->error_message);
    }
}
