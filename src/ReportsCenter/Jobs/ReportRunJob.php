<?php

declare(strict_types=1);

namespace Happones\Kinetix\ReportsCenter\Jobs;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Exports\FileWriter;
use Happones\Kinetix\Notifications\Notification;
use Happones\Kinetix\ReportsCenter\Report;
use Happones\Kinetix\ReportsCenter\ReportRun;
use Happones\Kinetix\ReportsCenter\ReportRunStatus;
use Happones\Kinetix\Support\KinetixDisk;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\File;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Chunks a `Report`'s query, writing rows to the configured disk while
 * tracking live progress on its `ReportRun` row. Mirrors `ExportProcessor`'s
 * tempnam → FileWriter → chunk → Storage::putFileAs pipeline, adding
 * progress counters and cooperative cancellation.
 *
 * "Cancelling" a run means THIS JOB's own loop breaks and returns early —
 * it never kills the queue-worker process itself, which is shared, long-lived
 * infrastructure that keeps processing other jobs. Cancellation is entirely
 * cooperative: a lightweight `status` column read at the top of `handle()`
 * (covers being cancelled before any worker picked the job up) and once per
 * chunk (covers cancelling mid-run) — this works identically across every
 * queue driver (database, Redis, SQS, Horizon) with no driver-specific
 * "delete a single pending job" support required.
 */
class ReportRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    protected string $directory = 'kinetix-report-runs';

    public int $tries = 3;

    public function __construct(protected int $runId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(): void
    {
        $status = DB::table('kinetix_report_runs')->where('id', $this->runId)->value('status');

        if ($status === null || $status === ReportRunStatus::Cancelled->value) {
            return;
        }

        $run = ReportRun::find($this->runId);

        if ($run === null) {
            return;
        }

        $run->forceFill([
            'status'         => ReportRunStatus::Running,
            'started_at'     => now(),
            'processed_rows' => 0,
        ])->save();

        $tempPath = null;

        try {
            $reportClass = $run->report_class;

            if (! class_exists($reportClass) || ! is_subclass_of($reportClass, Report::class)) {
                throw new RuntimeException("Unknown report class [{$reportClass}].");
            }

            /** @var Report $report */
            $report = (new $reportClass)->withParameters($run->parameters ?? []);
            $format = $report->format();

            $totalRows = null;

            if ($report->estimatesTotal()) {
                try {
                    $totalRows = $report->resolveExportQuery()->count();
                    $run->forceFill(['total_rows' => $totalRows])->save();
                } catch (Throwable) {
                    $totalRows = null;
                }
            }

            $storedName   = Str::uuid()->toString().'.'.$format;
            $relativePath = $this->directory.'/'.$storedName;
            $disk         = KinetixDisk::privateName();
            $tempPath     = (string) tempnam(sys_get_temp_dir(), 'kinetix_report_');

            $writer = new FileWriter($tempPath, $format);
            $writer->writeRow($report->headings());

            $processed = 0;
            $cancelled = false;

            $report->resolveExportQuery()->chunk($report->chunkSize(), function ($records) use (
                $writer,
                $report,
                &$processed,
                &$cancelled,
                $run,
                $totalRows,
            ): bool {
                $current = DB::table('kinetix_report_runs')->where('id', $run->id)->value('status');

                if ($current === ReportRunStatus::Cancelled->value) {
                    $cancelled = true;

                    return false;
                }

                foreach ($records as $record) {
                    $writer->writeRow($report->mapRecord($record));
                }
                $processed += count($records);

                DB::table('kinetix_report_runs')->where('id', $run->id)->update([
                    'processed_rows' => $processed,
                    'percent'        => $totalRows
                        ? min(100, (int) round($processed / max($totalRows, 1) * 100))
                        : null,
                    'updated_at' => now(),
                ]);

                return true;
            });

            if ($cancelled) {
                $writer->close();
                $run->forceFill([
                    'status'       => ReportRunStatus::Cancelled,
                    'cancelled_at' => now(),
                ])->save();

                return;
            }

            $summaryRow = $report->summaryRow($report->resolveExportQuery());

            if ($summaryRow !== null) {
                $writer->writeRow($summaryRow);
            }

            $writer->close();

            Storage::disk($disk)->putFileAs($this->directory, new File($tempPath), $storedName);

            $retentionDays = (int) config('kinetix.reports_center.retention_days', 7);

            $run->forceFill([
                'status'         => ReportRunStatus::Completed,
                'processed_rows' => $processed,
                'percent'        => 100,
                'disk'           => $disk,
                'path'           => $relativePath,
                'file_name'      => $report->fileName().'.'.$format,
                'completed_at'   => now(),
                'expires_at'     => now()->addDays($retentionDays),
            ])->save();

            $this->notify($run->fresh());
        } catch (Throwable $e) {
            // Do NOT mark the row failed here — $tries/backoff may still retry
            // this job successfully. Only failed() (called once, after retries
            // are exhausted) writes the terminal state, to avoid a misleading
            // "Failed" flash mid-retry on a transient error.
            throw $e;
        } finally {
            if ($tempPath !== null && is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Guaranteed to run exactly once, after $tries/backoff are exhausted.
     */
    public function failed(Throwable $e): void
    {
        ReportRun::whereKey($this->runId)->update([
            'status'        => ReportRunStatus::Failed->value,
            'error_message' => $e->getMessage(),
            'completed_at'  => now(),
        ]);
    }

    protected function notify(?ReportRun $run): void
    {
        if ($run === null) {
            return;
        }

        $recipient = null;

        if ($run->launched_by_id !== null) {
            $recipient = $run->launchedBy;
        } elseif ($run->report_schedule_id !== null) {
            $schedule = $run->schedule;

            if ($schedule !== null && $schedule->notify_on_completion) {
                $recipient = $schedule->createdBy;
            }
        }

        if ($recipient === null) {
            return;
        }

        $url = route('kinetix.report-runs.download', $run);

        $notification = Notification::make()
            ->title((string) __('kinetix.report_run_ready'))
            ->body((string) __('kinetix.report_run_ready_body'))
            ->success()
            ->actions([
                Action::make('download')
                    ->label((string) __('kinetix.download_export'))
                    ->icon('download')
                    ->color('primary')
                    ->button()
                    ->url($url, true),
            ]);

        if (Notification::shouldBroadcast()) {
            $notification->broadcast($recipient);

            return;
        }

        $notification->sendToDatabase($recipient);
    }
}
