<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows\Nodes;

use Happones\Kinetix\Flows\FlowStep;
use Happones\Kinetix\Flows\NodeOutcome;

/** Waits `amount` `unit`s (minutes, hours, days) before going on. */
class DelayNode extends BaseNode
{
    public function execute(FlowStep $step): NodeOutcome
    {
        $amount = max(1, (int) ($step->data['amount'] ?? 1));
        $unit   = in_array($step->data['unit'] ?? null, ['minutes', 'hours', 'days'], true) ? $step->data['unit'] : 'minutes';

        return NodeOutcome::waitUntil($step->now()->copy()->add($unit, $amount));
    }
}
