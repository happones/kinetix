<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows\Nodes;

use Happones\Kinetix\Flows\FlowStep;
use Happones\Kinetix\Flows\NodeOutcome;

/** Where every flow begins (its trigger lives on the flow). */
class StartNode extends BaseNode
{
    public function execute(FlowStep $step): NodeOutcome
    {
        return NodeOutcome::next();
    }
}
