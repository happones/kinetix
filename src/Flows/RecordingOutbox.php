<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows;

/**
 * The simulator's outbox: nothing is sent; what the flow would do is kept for
 * the editor's test chat.
 */
class RecordingOutbox implements FlowOutbox
{
    /** @var list<array{kind: string, text: string, options: list<string>}> */
    public array $entries = [];

    public function say(string $text, array $options = []): void
    {
        $this->entries[] = ['kind' => 'bot', 'text' => $text, 'options' => $options];
    }

    public function note(string $text): void
    {
        $this->entries[] = ['kind' => 'note', 'text' => $text, 'options' => []];
    }

    public function assign(int|string|null $userKey): void
    {
        $this->system(__('kinetix::kinetix.flow_sim_handoff'));
    }

    /** Something the flow did that isn't a message: a wait, a handoff. */
    public function system(string $text): void
    {
        $this->entries[] = ['kind' => 'system', 'text' => $text, 'options' => []];
    }
}
