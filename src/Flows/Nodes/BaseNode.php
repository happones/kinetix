<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows\Nodes;

use Happones\Kinetix\Flows\FlowNode;
use Happones\Kinetix\Flows\FlowStep;
use Happones\Kinetix\Flows\NodeOutcome;

/** A node with one `next` output that never waits for a reply. */
abstract class BaseNode implements FlowNode
{
    public function outputs(array $data): array
    {
        return ['next'];
    }

    public function receive(FlowStep $step, string $input): NodeOutcome
    {
        return NodeOutcome::next();
    }

    public function problem(array $data): ?string
    {
        return null;
    }

    protected function missing(string $field): string
    {
        return __('kinetix::kinetix.flow_problem_required', ['field' => __("kinetix::kinetix.flow_field_{$field}")]);
    }
}
