<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows;

/**
 * Walks a graph from where a run stands: hands a reply to the node waiting
 * for it, then runs node after node until one waits (for a reply, a time)
 * or the flow ends. The same walk serves real runs and the simulator, which
 * skips waits on the spot.
 */
class FlowInterpreter
{
    public function __construct(protected NodeRegistry $nodes) {}

    /**
     * @param callable(string): void|null $entered called with each node the run enters
     */
    public function advance(FlowGraph $graph, FlowState $state, FlowOutbox $outbox, ?string $input = null, bool $simulation = false, ?callable $entered = null): void
    {
        $limit = (int) config('kinetix.flows.max_steps', 50);

        if ($state->node === null) {
            $start = $graph->start();

            if ($start === null) {
                $this->fail($state, __('kinetix::kinetix.flow_problem_no_start'));

                return;
            }

            $state->node   = $start['id'];
            $state->status = FlowRunStatus::Running;
            $this->enter($state->node, $entered);
        } elseif ($state->status === FlowRunStatus::WaitingReply) {
            $state->status = FlowRunStatus::Running;

            if (! $this->apply($graph, $state, $outbox, $this->receive($graph, $state, $outbox, (string) $input, $simulation), $simulation, $entered)) {
                return;
            }
        } elseif ($state->status === FlowRunStatus::WaitingDelay) {
            // The wait is over: on to the delay's next node.
            $state->status    = FlowRunStatus::Running;
            $state->waitUntil = null;

            if (! $this->follow($graph, $state, 'next', $entered)) {
                return;
            }
        }

        for ($steps = 0; $state->status === FlowRunStatus::Running; $steps++) {
            if ($steps >= $limit) {
                $this->fail($state, __('kinetix::kinetix.flow_error_loop'));

                return;
            }

            $node = $graph->node($state->node);
            $type = $node !== null ? $this->nodes->get($node['type']) : null;

            if ($node === null || $type === null) {
                $this->fail($state, __('kinetix::kinetix.flow_error_node'));

                return;
            }

            $outcome = $type->execute($this->step($graph, $node, $state, $outbox, $simulation));

            if (! $this->apply($graph, $state, $outbox, $outcome, $simulation, $entered)) {
                return;
            }
        }
    }

    protected function receive(FlowGraph $graph, FlowState $state, FlowOutbox $outbox, string $input, bool $simulation): NodeOutcome
    {
        $node = $graph->node($state->node);
        $type = $node !== null ? $this->nodes->get($node['type']) : null;

        if ($node === null || $type === null) {
            return NodeOutcome::stop(FlowRunStatus::Failed, __('kinetix::kinetix.flow_error_node'));
        }

        return $type->receive($this->step($graph, $node, $state, $outbox, $simulation), $input);
    }

    /**
     * Act on a node's outcome. False when the walk stops here.
     *
     * @param callable(string): void|null $entered
     */
    protected function apply(FlowGraph $graph, FlowState $state, FlowOutbox $outbox, NodeOutcome $outcome, bool $simulation, ?callable $entered): bool
    {
        switch ($outcome->kind) {
            case 'wait_reply':
                $state->status = FlowRunStatus::WaitingReply;

                return false;

            case 'wait_delay':
                if ($simulation) {
                    if ($outbox instanceof RecordingOutbox && $outcome->until !== null) {
                        $outbox->system(__('kinetix::kinetix.flow_sim_wait', ['time' => $outcome->until->diffForHumans(now(), ['syntax' => 1, 'parts' => 2])]));
                    }

                    return $this->follow($graph, $state, 'next', $entered);
                }

                $state->status    = FlowRunStatus::WaitingDelay;
                $state->waitUntil = $outcome->until;

                return false;

            case 'stop':
                $state->status = $outcome->status ?? FlowRunStatus::Completed;
                $state->error  = $outcome->error;

                return false;

            default:
                return $this->follow($graph, $state, $outcome->handle ?? 'next', $entered);
        }
    }

    /**
     * Move along an output; the flow ends where an output leads nowhere.
     *
     * @param callable(string): void|null $entered
     */
    protected function follow(FlowGraph $graph, FlowState $state, string $handle, ?callable $entered): bool
    {
        $target = $state->node !== null ? $graph->next($state->node, $handle) : null;

        if ($target === null) {
            $state->status = FlowRunStatus::Completed;

            return false;
        }

        $state->node = $target;
        $this->enter($target, $entered);

        return true;
    }

    /**
     * @param array{id: string, type: string, data: array<string, mixed>} $node
     */
    protected function step(FlowGraph $graph, array $node, FlowState $state, FlowOutbox $outbox, bool $simulation): FlowStep
    {
        $type  = $this->nodes->get($node['type']);
        $wired = array_values(array_filter(
            $type?->outputs($node['data']) ?? [],
            static fn (string $output): bool => $graph->hasEdge($node['id'], $output),
        ));

        return new FlowStep($node['id'], $node['data'], $state, $outbox, $simulation, $wired);
    }

    /**
     * @param callable(string): void|null $entered
     */
    protected function enter(string $node, ?callable $entered): void
    {
        if ($entered !== null) {
            $entered($node);
        }
    }

    protected function fail(FlowState $state, string $error): void
    {
        $state->status = FlowRunStatus::Failed;
        $state->error  = $error;
    }
}
