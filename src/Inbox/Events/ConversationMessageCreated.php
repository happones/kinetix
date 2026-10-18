<?php

declare(strict_types=1);

namespace Happones\Kinetix\Inbox\Events;

use Happones\Kinetix\Inbox\Conversation;
use Happones\Kinetix\Inbox\ConversationMessage;
use Illuminate\Foundation\Events\Dispatchable;

/** A contact's message was recorded in a conversation — the hook for bots and flows. */
class ConversationMessageCreated
{
    use Dispatchable;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly ConversationMessage $message,
    ) {}
}
