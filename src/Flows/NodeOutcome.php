<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows;

use Carbon\CarbonInterface;

/** What a node decided: follow an output, wait for a reply or a time, or stop the flow. */
final class NodeOutcome
{
    private function __construct(
        public readonly string $kind,
        public readonly ?string $handle = null,
        public readonly ?CarbonInterface $until = null,
        public readonly ?FlowRunStatus $status = null,
        public readonly ?string $error = null,
    ) {}

    public static function next(string $handle = 'next'): self
    {
        return new self('next', handle: $handle);
    }

    public static function waitReply(): self
    {
        return new self('wait_reply');
    }

    public static function waitUntil(CarbonInterface $until): self
    {
        return new self('wait_delay', until: $until);
    }

    public static function stop(FlowRunStatus $status = FlowRunStatus::Completed, ?string $error = null): self
    {
        return new self('stop', status: $status, error: $error);
    }
}
