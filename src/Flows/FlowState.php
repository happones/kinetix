<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows;

use Carbon\CarbonInterface;

/**
 * A run's working state — the node it's on, its variables, the invalid
 * answers to the current question — shared by real runs and the simulator.
 */
final class FlowState
{
    /**
     * @param array<string, mixed> $variables
     */
    public function __construct(
        public ?string $node = null,
        public array $variables = [],
        public int $attempts = 0,
        public FlowRunStatus $status = FlowRunStatus::Running,
        public ?CarbonInterface $waitUntil = null,
        public ?string $error = null,
    ) {}

    /**
     * @return array{node: string|null, variables: array<string, mixed>, attempts: int, status: string}
     */
    public function toArray(): array
    {
        return [
            'node'      => $this->node,
            'variables' => $this->variables,
            'attempts'  => $this->attempts,
            'status'    => $this->status->value,
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function fromArray(array $state): self
    {
        return new self(
            is_string($state['node'] ?? null) ? $state['node'] : null,
            is_array($state['variables'] ?? null) ? $state['variables'] : [],
            (int) ($state['attempts'] ?? 0),
            FlowRunStatus::tryFrom((string) ($state['status'] ?? '')) ?? FlowRunStatus::Running,
        );
    }
}
