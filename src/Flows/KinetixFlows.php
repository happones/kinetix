<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows;

use Happones\Kinetix\Inbox\Conversation;

/**
 * The static entry point to bot flows: add a kind of node, start a flow in a
 * conversation from your code.
 *
 * ```php
 * KinetixFlows::node('crm-lookup', CrmLookupNode::class);
 * KinetixFlows::start($flow, $conversation);
 * ```
 */
class KinetixFlows
{
    /**
     * @param class-string<FlowNode>|FlowNode $node
     */
    public static function node(string $type, string|FlowNode $node): void
    {
        app(NodeRegistry::class)->register($type, $node);
    }

    public static function start(Flow $flow, Conversation $conversation): ?FlowRun
    {
        return app(FlowEngine::class)->start($flow, $conversation);
    }
}
