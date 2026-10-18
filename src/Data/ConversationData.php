<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Happones\Kinetix\Inbox\Conversation;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ConversationData extends Data
{
    /**
     * @param array{id: int|string, provider: string, name: string} $channel
     * @param array{id: int|string, name: string|null}|null         $assignee
     */
    public function __construct(
        public int|string $id,
        public array $channel,
        public string $contactKey,
        public ?string $contactName,
        public bool $isGroup,
        public ?array $assignee,
        public ?ConversationMessageData $lastMessage,
        public ?string $lastMessageAt,
        public int $unread,
        public bool $archived,
    ) {}

    public static function fromModel(Conversation $conversation, int $unread = 0, ?string $assigneeName = null): self
    {
        $last = $conversation->lastMessage;

        return new self(
            $conversation->getKey(),
            [
                'id'       => $conversation->channel->getKey(),
                'provider' => $conversation->channel->provider,
                'name'     => $conversation->channel->name,
            ],
            $conversation->contact_key,
            $conversation->contact_name,
            $conversation->is_group,
            $conversation->assigned_to === null ? null : ['id' => $conversation->assigned_to, 'name' => $assigneeName],
            $last !== null ? ConversationMessageData::fromModel($last) : null,
            $conversation->last_message_at?->toIso8601String(),
            $unread,
            $conversation->archived_at !== null,
        );
    }
}
