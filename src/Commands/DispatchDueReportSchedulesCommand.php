<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\ReportsCenter\ReportRunDispatcher;
use Happones\Kinetix\ReportsCenter\ReportSchedule;
use Illuminate\Console\Command;

/**
 * Fire every due `ReportSchedule`: dispatches a tracked `ReportRun` for it
 * and advances `next_run_at`. Run this from the scheduler — same "Kinetix
 * doesn't own cron, the host app does" convention as `kinetix:reports:send`:
 *
 *     $schedule->command('kinetix:report-schedules:dispatch-due')->everyMinute();
 */
class DispatchDueReportSchedulesCommand extends Command
{
    protected $signature = 'kinetix:report-schedules:dispatch-due';

    protected $description = 'Dispatch every due Kinetix report schedule';

    public function handle(ReportRunDispatcher $dispatcher): int
    {
        $due = ReportSchedule::query()
            ->where('enabled', true)
            ->where(function ($query) {
                $query->whereNull('next_run_at')->orWhere('next_run_at', '<=', now());
            })
            ->get();

        foreach ($due as $schedule) {
            $dispatcher->dispatch(
                report: $schedule->report(),
                launchedBy: $schedule->createdBy,
                parameters: $schedule->parameters ?? [],
                reportScheduleId: $schedule->id,
            );

            $nextRunAt = $schedule->frequency->next(now());

            $schedule->update([
                'last_run_at' => now(),
                'next_run_at' => $nextRunAt,
                'enabled'     => $nextRunAt === null ? false : $schedule->enabled,
            ]);
        }

        $count = $due->count();
        $this->info("{$count} schedule".($count === 1 ? '' : 's').' dispatched.');

        return self::SUCCESS;
    }
}
