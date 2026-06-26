<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\Webhooks\WebhookLog;
use Illuminate\Console\Command;

/**
 * Prune webhook delivery logs older than the retention window. Schedule it so
 * `kinetix_webhook_logs` stays bounded.
 */
class WebhooksPruneCommand extends Command
{
    protected $signature = 'kinetix:webhooks:prune {--days= : Override kinetix.webhooks.retention_days}';

    protected $description = 'Prune Kinetix webhook delivery logs older than the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('kinetix.webhooks.retention_days', 30));

        $deleted = (int) WebhookLog::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Pruned {$deleted} webhook log".($deleted === 1 ? '' : 's')." older than {$days} days.");

        return self::SUCCESS;
    }
}
