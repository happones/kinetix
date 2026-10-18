<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows;

use Happones\Kinetix\Inbox\Conversation;
use Happones\Kinetix\Inbox\InboxManager;

/** A real run's outbox: the flow speaks in the conversation through the inbox, as the bot. */
class InboxOutbox implements FlowOutbox
{
    public function __construct(
        protected InboxManager $inbox,
        protected Conversation $conversation,
        protected string $botName,
    ) {}

    public function say(string $text, array $options = []): void
    {
        if (trim($text) === '' && $options === []) {
            return;
        }

        $this->inbox->sendAsBot($this->conversation, $text, $this->botName, $options);
    }

    public function note(string $text): void
    {
        $this->inbox->sendAsBot($this->conversation, $text, $this->botName, note: true);
    }

    public function assign(int|string|null $userKey): void
    {
        $this->inbox->assign($this->conversation, $userKey);
    }
}
