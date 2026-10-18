<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows\Nodes;

use Happones\Kinetix\Flows\FlowStep;
use Happones\Kinetix\Flows\NodeOutcome;

/** Keeps a value — text with `{{variables}}` — in `variable`. */
class SetVariableNode extends BaseNode
{
    public function execute(FlowStep $step): NodeOutcome
    {
        $step->setVariable((string) ($step->data['variable'] ?? ''), $step->render((string) ($step->data['value'] ?? '')));

        return NodeOutcome::next();
    }

    public function problem(array $data): ?string
    {
        return trim((string) ($data['variable'] ?? '')) === '' ? $this->missing('variable') : null;
    }
}
