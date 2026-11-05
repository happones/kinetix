<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\ReportsCenter\ReportAccess;
use Happones\Kinetix\ReportsCenter\ReportRunDispatcher;
use Happones\Kinetix\ReportsCenter\ReportSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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

        $dispatched = 0;

        foreach ($due as $schedule) {
            $report = $schedule->report();

            // The creator must still be allowed to run the report: a role
            // lost since the schedule was saved stops the firing (the
            // schedule still advances, so it doesn't warn every minute).
            // Judged in the schedule's team: the console has no request team,
            // so a role held in that team must be read there.
            if (! ReportAccess::allowsInTeam($report, $schedule->createdBy, $schedule->team_id)) {
                Log::warning('Kinetix skipped a report schedule its creator may no longer run.', [
                    'schedule' => $schedule->id,
                    'report'   => $schedule->report_class,
                    'user'     => $schedule->created_by_id,
                ]);
                $this->warn("Schedule #{$schedule->id} skipped: its creator may no longer run {$schedule->report_class}.");
                $this->advance($schedule);

                continue;
            }

            $dispatcher->dispatch(
                report: $report,
                launchedBy: $schedule->createdBy,
                parameters: $schedule->parameters ?? [],
                reportScheduleId: $schedule->id,
                // Console run: no request to resolve the team from, so the run
                // inherits the schedule's tenant.
                teamId: $schedule->team_id,
            );

            $this->advance($schedule, ['last_run_at' => now()]);
            $dispatched++;
        }

        $this->info("{$dispatched} schedule".($dispatched === 1 ? '' : 's').' dispatched.');

        return self::SUCCESS;
    }

    /**
     * Move the schedule to its next firing; a one-off one is disabled.
     *
     * @param array<string, mixed> $attributes
     */
    protected function advance(ReportSchedule $schedule, array $attributes = []): void
    {
        $nextRunAt = $schedule->frequency->next(now());

        $schedule->update([
            ...$attributes,
            'next_run_at' => $nextRunAt,
            'enabled'     => $nextRunAt === null ? false : $schedule->enabled,
        ]);
    }
}
