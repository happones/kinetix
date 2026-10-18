<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows;

use Happones\Kinetix\Flows\Nodes\ConditionNode;
use Happones\Kinetix\Flows\Nodes\DelayNode;
use Happones\Kinetix\Flows\Nodes\EndNode;
use Happones\Kinetix\Flows\Nodes\HandoffNode;
use Happones\Kinetix\Flows\Nodes\HoursNode;
use Happones\Kinetix\Flows\Nodes\HttpNode;
use Happones\Kinetix\Flows\Nodes\MessageNode;
use Happones\Kinetix\Flows\Nodes\OptionsNode;
use Happones\Kinetix\Flows\Nodes\QuestionNode;
use Happones\Kinetix\Flows\Nodes\SetVariableNode;
use Happones\Kinetix\Flows\Nodes\StartNode;

/** The kinds of node a flow can use: the built-in ones, plus any an app registers. */
class NodeRegistry
{
    /** @var array<string, class-string<FlowNode>|FlowNode> */
    protected array $types = [
        'start'     => StartNode::class,
        'message'   => MessageNode::class,
        'question'  => QuestionNode::class,
        'options'   => OptionsNode::class,
        'condition' => ConditionNode::class,
        'set'       => SetVariableNode::class,
        'http'      => HttpNode::class,
        'delay'     => DelayNode::class,
        'hours'     => HoursNode::class,
        'handoff'   => HandoffNode::class,
        'end'       => EndNode::class,
    ];

    /** @var array<string, FlowNode> */
    protected array $resolved = [];

    /**
     * @param class-string<FlowNode>|FlowNode $node
     */
    public function register(string $type, string|FlowNode $node): void
    {
        $this->types[$type] = $node;
        unset($this->resolved[$type]);
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    public function get(string $type): ?FlowNode
    {
        if (! $this->has($type)) {
            return null;
        }

        return $this->resolved[$type] ??= ($this->types[$type] instanceof FlowNode ? $this->types[$type] : app($this->types[$type]));
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->types);
    }
}
