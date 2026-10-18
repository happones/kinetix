<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Resume a run once its delay is over. Harmless if it runs early (a sync
 * queue) or twice: the run only moves when its wait is really over;
 * `kinetix:flows:resume` catches up whatever the queue missed.
 */
class ResumeFlowRun implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public int|string $runId) {}

    public function handle(FlowEngine $engine): void
    {
        $run = FlowRun::query()->find($this->runId);

        if ($run !== null) {
            $engine->resume($run);
        }
    }
}
