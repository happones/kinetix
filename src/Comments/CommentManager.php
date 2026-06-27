<?php

declare(strict_types=1);

namespace Happones\Kinetix\Comments;

use Happones\Kinetix\Data\CommentData;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Lists, creates, edits and deletes comments on a commentable model. Comments
 * are threaded one level deep (top-level comments + replies).
 */
class CommentManager
{
    /**
     * The commentable's comment tree (top-level first, newest replies under each),
     * as DTOs with an `editable` flag for the current user.
     *
     * @return array<int, CommentData>
     */
    public function for(Model $commentable, ?Model $currentUser = null): array
    {
        /** @var Collection<int, Comment> $comments */
        $comments = Comment::query()
            ->where('commentable_type', $commentable->getMorphClass())
            ->where('commentable_id', $commentable->getKey())
            ->with('author')
            ->oldest()
            ->get();

        // Group children by their (stringified) parent id; top-level → ''.
        $byParent = $comments->groupBy(fn (Comment $c): string => (string) $c->parent_id);

        return ($byParent->get('') ?? collect())
            ->map(function (Comment $c) use ($byParent, $currentUser): CommentData {
                $replies = ($byParent->get((string) $c->getKey()) ?? collect())
                    ->map(fn (Comment $r): CommentData => CommentData::fromModel($r, $currentUser))
                    ->all();

                return CommentData::fromModel($c, $currentUser, $replies);
            })
            ->values()
            ->all();
    }

    public function create(Model $commentable, Model $user, string $body, int|string|null $parentId = null): Comment
    {
        $comment = new Comment([
            'user_id'   => $user->getKey(),
            'body'      => $body,
            'parent_id' => $parentId,
        ]);
        $comment->commentable()->associate($commentable);
        $comment->save();
        $comment->setRelation('author', $user);

        return $comment;
    }

    public function update(Comment $comment, string $body): Comment
    {
        $comment->update(['body' => $body]);

        return $comment;
    }

    public function delete(Comment $comment): void
    {
        // Remove the comment and any threaded replies.
        Comment::query()->where('parent_id', $comment->getKey())->delete();
        $comment->delete();
    }
}
