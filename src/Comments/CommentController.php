<?php

declare(strict_types=1);

namespace Happones\Kinetix\Comments;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Self-service comments. Anyone who may view a commentable can read and post
 * comments on it; each user may edit and delete only their own. The commentable
 * model must be allowlisted via KinetixComments::for([...]).
 */
class CommentController
{
    public function __construct(
        protected CommentManager $manager,
        protected CommentRegistry $registry,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $commentable = $this->commentable($request);

        return response()->json([
            'comments' => $this->manager->for($commentable, $this->user($request)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $commentable = $this->commentable($request);
        $user        = $this->user($request);

        $validated = $request->validate([
            'body'      => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable'],
        ]);

        // A reply must target an existing top-level comment on this commentable.
        $parentId = $validated['parent_id'] ?? null;
        if ($parentId !== null && ! $this->isValidParent($commentable, $parentId)) {
            abort(422, __('kinetix.comment_invalid_parent'));
        }

        $this->manager->create($commentable, $user, $validated['body'], $parentId);

        return response()->json([
            'comments' => $this->manager->for($commentable, $user),
        ], 201);
    }

    public function update(Request $request, int|string $comment): JsonResponse
    {
        $user  = $this->user($request);
        $model = $this->ownComment($user, $comment);

        $validated = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $this->manager->update($model, $validated['body']);

        return response()->json(['status' => 'success']);
    }

    public function destroy(Request $request, int|string $comment): JsonResponse
    {
        $user  = $this->user($request);
        $model = $this->ownComment($user, $comment);

        $this->manager->delete($model);

        return response()->json(['status' => 'success']);
    }

    protected function user(Request $request): Model
    {
        $user = $request->user();

        abort_if(! $user instanceof Model, 401);

        return $user;
    }

    /**
     * Resolve + authorize the commentable from the request (type + id).
     */
    protected function commentable(Request $request): Model
    {
        $type = (string) $request->input('commentable_type');
        $id   = $request->input('commentable_id');

        $class = $this->registry->resolve($type);
        abort_if($class === null, 404);

        $model = $class::query()->find($id);
        abort_if(! $model instanceof Model, 404);

        // Respect a host 'view' policy when one is defined for the model.
        if (Gate::getPolicyFor($model) !== null && Gate::denies('view', $model)) {
            abort(403);
        }

        return $model;
    }

    protected function ownComment(Model $user, int|string $id): Comment
    {
        $comment = Comment::query()->find($id);

        abort_if(! $comment instanceof Comment, 404);
        abort_unless((string) $comment->user_id === (string) $user->getKey(), 403);

        return $comment;
    }

    protected function isValidParent(Model $commentable, int|string $parentId): bool
    {
        return Comment::query()
            ->whereKey($parentId)
            ->where('commentable_type', $commentable->getMorphClass())
            ->where('commentable_id', $commentable->getKey())
            ->whereNull('parent_id')
            ->exists();
    }
}
