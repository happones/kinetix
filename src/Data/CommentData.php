<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Happones\Kinetix\Comments\Comment;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CommentData extends Data
{
    /**
     * @param array<int, CommentData> $replies
     */
    public function __construct(
        public int|string|null $id,
        public string $body,
        public int|string|null $authorId,
        public ?string $authorName,
        public ?string $authorAvatar,
        public int|string|null $parentId,
        public ?string $createdAt,
        public bool $edited,
        public bool $editable,
        public array $replies = [],
    ) {}

    /**
     * @param array<int, CommentData> $replies
     */
    public static function fromModel(Comment $comment, ?Model $currentUser = null, array $replies = []): self
    {
        $author    = $comment->getRelationValue('author');
        $createdAt = $comment->created_at;

        return new self(
            id: $comment->getKey(),
            body: (string) $comment->body,
            authorId: $comment->user_id,
            authorName: $author instanceof Model ? (string) $author->getAttribute('name') : null,
            authorAvatar: $author instanceof Model ? $author->getAttribute('avatar') : null,
            parentId: $comment->parent_id,
            createdAt: $createdAt instanceof \DateTimeInterface ? $createdAt->format(\DateTimeInterface::ATOM) : null,
            edited: $comment->updated_at !== null && $comment->created_at !== null
                                                  && $comment->updated_at->ne($comment->created_at),
            editable: $currentUser !== null       && (string) $comment->user_id === (string) $currentUser->getKey(),
            replies: $replies,
        );
    }
}
