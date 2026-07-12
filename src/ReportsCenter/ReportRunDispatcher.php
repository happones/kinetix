<?php

declare(strict_types=1);

namespace Happones\Kinetix\ReportsCenter;

use Carbon\CarbonInterface;
use Happones\Kinetix\ReportsCenter\Jobs\ReportRunJob;
use Illuminate\Database\Eloquent\Model;

/**
 * The single place that creates a `ReportRun` row and dispatches its job —
 * used by `Report::schedule()` (ad-hoc launch), the retry controller action,
 * and `kinetix:report-schedules:dispatch-due` (recurring firings).
 */
class ReportRunDispatcher
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function dispatch(
        Report $report,
        ?CarbonInterface $at = null,
        ?Model $launchedBy = null,
        array $parameters = [],
        ?int $reportScheduleId = null,
    ): ReportRun {
        $run = ReportRun::create([
            'report_schedule_id' => $reportScheduleId,
            'report_class'       => $report::class,
            'status'             => ReportRunStatus::Pending,
            'format'             => $report->format(),
            'parameters'         => $parameters,
            'launched_by_id'     => $launchedBy?->getKey(),
        ]);

        $pending = ReportRunJob::dispatch($run->id);

        if ($at !== null) {
            $pending->delay($at);
        }

        // Only pin a specific queue when the report defines one; otherwise use
        // the connection's default queue (mirrors Exporter::export()'s same
        // reasoning — config('queue.default') is a connection name, not a
        // queue, and Horizon wouldn't pick it up).
        if (($queue = $report->queue()) !== null) {
            $pending->onQueue($queue);
        }

        return $run;
    }
}
