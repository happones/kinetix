<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows;

/** Where a flow stands in a conversation. */
enum FlowRunStatus: string
{
    case Running      = 'running';
    case WaitingReply = 'waiting_reply';
    case WaitingDelay = 'waiting_delay';
    case Completed    = 'completed';
    case HandedOff    = 'handed_off';
    case Paused       = 'paused';
    case Failed       = 'failed';

    /** Still in charge of the conversation (it may be waiting). */
    public function isActive(): bool
    {
        return in_array($this, [self::Running, self::WaitingReply, self::WaitingDelay], true);
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return [self::Running->value, self::WaitingReply->value, self::WaitingDelay->value];
    }
}
