<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows\Nodes;

use Happones\Kinetix\Flows\FlowStep;
use Happones\Kinetix\Flows\NodeOutcome;

/** Says something to the contact: `text`, with `{{variables}}`. */
class MessageNode extends BaseNode
{
    public function execute(FlowStep $step): NodeOutcome
    {
        $step->outbox->say($step->render((string) ($step->data['text'] ?? '')));

        return NodeOutcome::next();
    }

    public function problem(array $data): ?string
    {
        return trim((string) ($data['text'] ?? '')) === '' ? $this->missing('text') : null;
    }
}
