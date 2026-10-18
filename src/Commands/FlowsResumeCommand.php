<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\Flows\FlowEngine;
use Illuminate\Console\Command;

/**
 * Resume flows whose delay is over — a safety net for the queue. Schedule it
 * every minute.
 */
class FlowsResumeCommand extends Command
{
    protected $signature = 'kinetix:flows:resume';

    protected $description = 'Resume Kinetix flows whose delay is over';

    public function handle(FlowEngine $engine): int
    {
        $count = $engine->resumeDue();

        $this->info("Resumed {$count} flow".($count === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
