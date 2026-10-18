<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Happones\Kinetix\Inbox\ConversationMessage;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ConversationMessageData extends Data
{
    /**
     * @param array{id: int|string, body: string|null, type: string, direction: string}|null $replyTo
     */
    public function __construct(
        public int|string $id,
        public int|string $conversationId,
        public string $direction,
        public string $type,
        public ?string $body,
        public string $status,
        public ?string $error,
        /** Whether its file is ready to show (an inbound file is fetched on the queue). */
        public bool $hasMedia,
        public bool $mediaReady,
        public ?string $mediaMime,
        public ?string $mediaName,
        public ?int $mediaSize,
        public ?array $replyTo,
        public ?string $authorName,
        public ?string $sentAt,
        /** Sent by a bot (a flow), not a person. */
        public bool $bot = false,
    ) {}

    public static function fromModel(ConversationMessage $message, ?string $authorName = null): self
    {
        $reply = $message->replyTo;

        return new self(
            $message->getKey(),
            $message->conversation_id,
            $message->direction->value,
            $message->type,
            $message->body,
            $message->status->value,
            $message->error,
            $message->hasMedia(),
            $message->media_path !== null,
            $message->media_mime,
            $message->media_name,
            $message->media_size,
            $reply === null ? null : [
                'id'        => $reply->getKey(),
                'body'      => $reply->body,
                'type'      => $reply->type,
                'direction' => $reply->direction->value,
            ],
            $authorName ?? (is_string($message->meta['author_name'] ?? null) ? $message->meta['author_name'] : null),
            ($message->sent_at ?? $message->created_at)?->toIso8601String(),
            ($message->meta['bot'] ?? false) === true,
        );
    }
}
