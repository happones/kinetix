<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows\Nodes;

use Happones\Kinetix\Flows\FlowRunStatus;
use Happones\Kinetix\Flows\FlowStep;
use Happones\Kinetix\Flows\NodeOutcome;

/**
 * Hands the conversation to the team: assigns it (`assign_to`, or leaves it
 * for anyone), leaves an internal `note` and stops the bot.
 */
class HandoffNode extends BaseNode
{
    public function outputs(array $data): array
    {
        return [];
    }

    public function execute(FlowStep $step): NodeOutcome
    {
        if (($note = trim($step->render((string) ($step->data['note'] ?? '')))) !== '') {
            $step->outbox->note($note);
        }

        $assignee = $step->data['assign_to'] ?? null;
        $step->outbox->assign(is_scalar($assignee) && $assignee !== '' ? $assignee : null);

        return NodeOutcome::stop(FlowRunStatus::HandedOff);
    }
}
