<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\Api\ApiLog;
use Illuminate\Console\Command;

/**
 * Prune API request logs older than the retention window. Schedule it so
 * `kinetix_api_logs` stays bounded.
 */
class ApiLogsPruneCommand extends Command
{
    protected $signature = 'kinetix:api-logs:prune {--days= : Override kinetix.api_logs.retention_days}';

    protected $description = 'Prune Kinetix API request logs older than the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('kinetix.api_logs.retention_days', 30));

        $deleted = (int) ApiLog::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Pruned {$deleted} API log".($deleted === 1 ? '' : 's')." older than {$days} days.");

        return self::SUCCESS;
    }
}
