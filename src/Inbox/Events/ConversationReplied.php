<?php

declare(strict_types=1);

namespace Happones\Kinetix\Inbox\Events;

use Happones\Kinetix\Inbox\Conversation;
use Happones\Kinetix\Inbox\ConversationMessage;
use Illuminate\Foundation\Events\Dispatchable;

/** Someone on the team answered a conversation (not a bot, not a note). */
class ConversationReplied
{
    use Dispatchable;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly ConversationMessage $message,
    ) {}
}
