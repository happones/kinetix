<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\Reports\ReportRegistry;
use Happones\Kinetix\Reports\ReportRunner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Generate and email scheduled reports. Run from the scheduler, filtering by
 * frequency so each cadence fires the right reports:
 *
 *     $schedule->command('kinetix:reports:send --frequency=daily')->dailyAt('06:00');
 *
 * Or run one on demand: `kinetix:reports:send daily-orders`.
 */
class SendReportsCommand extends Command
{
    protected $signature = 'kinetix:reports:send
        {report? : Run a single report by key}
        {--frequency= : Only reports with this frequency (daily|weekly|monthly|…)}';

    protected $description = 'Generate and email Kinetix scheduled reports';

    public function handle(ReportRegistry $registry, ReportRunner $runner): int
    {
        $key = $this->argument('report');

        if (is_string($key) && $key !== '') {
            $report = $registry->get($key);

            if ($report === null) {
                $this->error("Unknown report [{$key}].");

                return self::FAILURE;
            }

            $reports = [$report];
        } else {
            $frequency = $this->option('frequency');
            $reports   = $registry->due(is_string($frequency) && $frequency !== '' ? $frequency : null);
        }

        if ($reports === []) {
            $this->info('No reports to send.');

            return self::SUCCESS;
        }

        $sent = 0;
        foreach ($reports as $report) {
            try {
                if ($runner->run($report)) {
                    $sent++;
                    $this->line("Sent report [{$report->getKey()}] to ".count($report->getRecipients()).' recipient(s).');
                } else {
                    $this->warn("Skipped report [{$report->getKey()}] — no recipients.");
                }
            } catch (Throwable $e) {
                $this->error("Report [{$report->getKey()}] failed: {$e->getMessage()}");
            }
        }

        $this->info("Sent {$sent} report".($sent === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
