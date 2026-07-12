<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\ReportsCenter\ReportRun;
use Happones\Kinetix\ReportsCenter\ReportRunStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Prune old report runs: deletes the generated file (+ row) for completed
 * runs past `expires_at`, and the row alone (no file exists) for failed or
 * cancelled runs older than the same retention window.
 */
class ReportRunsPruneCommand extends Command
{
    protected $signature = 'kinetix:report-runs:prune {--days= : Override kinetix.reports_center.retention_days}';

    protected $description = 'Prune old Kinetix report runs (and their generated files)';

    public function handle(): int
    {
        $days   = (int) ($this->option('days') ?? config('kinetix.reports_center.retention_days', 7));
        $cutoff = now()->subDays($days);

        $expiredCompleted = ReportRun::query()
            ->where('status', ReportRunStatus::Completed->value)
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expiredCompleted as $run) {
            if ($run->disk !== null && $run->path !== null) {
                Storage::disk($run->disk)->delete($run->path);
            }

            $run->delete();
        }

        $staleOthers = ReportRun::query()
            ->whereIn('status', [ReportRunStatus::Failed->value, ReportRunStatus::Cancelled->value])
            ->where('created_at', '<', $cutoff)
            ->delete();

        $total = $expiredCompleted->count() + $staleOthers;
        $this->info("Pruned {$total} report run".($total === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
