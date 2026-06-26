<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\Activity\ActivityLogger;
use Illuminate\Console\Command;

/**
 * Deletes activity entries older than the retention window. Schedule it so the
 * `kinetix_activity` table doesn't grow unbounded.
 */
class ActivityPruneCommand extends Command
{
    protected $signature = 'kinetix:activity:prune {--days= : Override kinetix.activity.retention_days}';

    protected $description = 'Prune Kinetix activity entries older than the retention window';

    public function handle(ActivityLogger $logger): int
    {
        $days = (int) ($this->option('days') ?? config('kinetix.activity.retention_days', 365));

        $deleted = $logger->prune($days);

        if ($logger->usesSpatie()) {
            $this->info("Delegated to spatie's activitylog:clean (entries older than {$days} days).");

            return self::SUCCESS;
        }

        $this->info("Pruned {$deleted} activity entr".($deleted === 1 ? 'y' : 'ies')." older than {$days} days.");

        return self::SUCCESS;
    }
}
