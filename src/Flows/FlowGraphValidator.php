<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows;

/**
 * What's wrong with a graph before it can be published: no start (or two),
 * a node of an unknown kind, a node missing its settings, a start leading
 * nowhere. Each problem names its node, so the editor can point at it.
 */
class FlowGraphValidator
{
    public function __construct(protected NodeRegistry $nodes) {}

    /**
     * @param  array<string, mixed>                            $graph
     * @return list<array{node: string|null, message: string}>
     */
    public function problems(array $graph): array
    {
        $flow     = new FlowGraph($graph);
        $problems = [];
        $starts   = array_filter($flow->nodes(), static fn (array $node): bool => $node['type'] === 'start');

        if (count($starts) !== 1) {
            $problems[] = ['node' => null, 'message' => __('kinetix::kinetix.flow_problem_no_start')];
        }

        foreach ($flow->nodes() as $node) {
            $type = $this->nodes->get($node['type']);

            if ($type === null) {
                $problems[] = ['node' => $node['id'], 'message' => __('kinetix::kinetix.flow_error_node')];

                continue;
            }

            if (($problem = $type->problem($node['data'])) !== null) {
                $problems[] = ['node' => $node['id'], 'message' => $problem];
            }
        }

        $start = $flow->start();

        if ($start !== null && $flow->next($start['id']) === null) {
            $problems[] = ['node' => $start['id'], 'message' => __('kinetix::kinetix.flow_problem_start_unwired')];
        }

        return $problems;
    }
}
