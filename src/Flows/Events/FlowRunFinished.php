<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows\Events;

use Happones\Kinetix\Flows\FlowRun;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A flow stopped in a conversation: completed, handed to the team, paused
 * (a person answered, another flow started) or failed (with its `error`).
 * Its variables hold what it collected.
 */
class FlowRunFinished
{
    use Dispatchable;

    public function __construct(public readonly FlowRun $run) {}
}
