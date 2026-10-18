<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows;

/**
 * A kind of step in a flow. `execute()` runs when the flow reaches it;
 * a node that waits for the contact gets the reply in `receive()`.
 * Register one with `KinetixFlows::node('crm', CrmNode::class)`.
 */
interface FlowNode
{
    /**
     * The outputs the node exposes with these settings (`next`, `yes`/`no`,
     * an option's id…), so the editor and the validator know them.
     *
     * @param  array<string, mixed> $data
     * @return list<string>
     */
    public function outputs(array $data): array;

    public function execute(FlowStep $step): NodeOutcome;

    /** The contact's reply to a node that waited for one. */
    public function receive(FlowStep $step, string $input): NodeOutcome;

    /**
     * What's wrong with the node's settings, for the editor (null when
     * nothing): a translated, user-facing message.
     *
     * @param array<string, mixed> $data
     */
    public function problem(array $data): ?string;
}
