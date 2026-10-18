<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows\Nodes;

use Happones\Kinetix\Flows\FlowStep;
use Happones\Kinetix\Flows\NodeOutcome;

/** Ends the flow, optionally saying goodbye (`text`). */
class EndNode extends BaseNode
{
    public function outputs(array $data): array
    {
        return [];
    }

    public function execute(FlowStep $step): NodeOutcome
    {
        if (($text = trim($step->render((string) ($step->data['text'] ?? '')))) !== '') {
            $step->outbox->say($text);
        }

        return NodeOutcome::stop();
    }
}
