<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows;

use Carbon\CarbonInterface;

/**
 * One node at work: its settings, the run's state (variables live there),
 * where its actions go, and whether it's a simulation.
 */
final class FlowStep
{
    /**
     * @param array<string, mixed> $data
     * @param list<string>         $wired the node's outputs that lead somewhere
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly array $data,
        public readonly FlowState $state,
        public readonly FlowOutbox $outbox,
        public readonly bool $simulation = false,
        public readonly array $wired = [],
    ) {}

    /** Whether the node's output leads somewhere in the graph. */
    public function isWired(string $output): bool
    {
        return in_array($output, $this->wired, true);
    }

    /** A text of the node's with the run's variables filled in. */
    public function render(?string $template): string
    {
        return FlowTemplate::render($template, $this->state->variables);
    }

    public function setVariable(string $name, mixed $value): void
    {
        $name = trim($name);

        if ($name !== '') {
            data_set($this->state->variables, $name, $value);
        }
    }

    public function now(): CarbonInterface
    {
        return now();
    }
}
