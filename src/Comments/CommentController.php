<?php

declare(strict_types=1);

namespace Happones\Kinetix\Comments;

use Happones\Kinetix\Support\DescriptorAudience;
use Happones\Kinetix\Support\RecordReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service comments. Anyone who may view a commentable can read and post
 * comments on it; each user may edit and delete only their own. The commentable
 * model must be allowlisted via KinetixComments::for([...]), and is named by a
 * signed reference (KinetixComments::reference($model)).
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

    public function update(Request $request): JsonResponse
    {
        // By name: under the team prefix (`{current_team}`) a positional
        // argument would receive the team, not the comment.
        $comment = (string) $request->route('comment');

        $user  = $this->user($request);
        $model = $this->ownComment($user, $comment);

        $validated = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $this->manager->update($model, $validated['body']);

        return response()->json(['status' => 'success']);
    }

    public function destroy(Request $request): JsonResponse
    {
        // By name: under the team prefix (`{current_team}`) a positional
        // argument would receive the team, not the comment.
        $comment = (string) $request->route('comment');

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
     * Resolve + authorize the commentable from the request: a signed
     * `reference` ({@see KinetixComments::reference()}), or the legacy
     * `commentable_type` + `commentable_id` when `comments.require_reference`
     * allows it.
     */
    protected function commentable(Request $request): Model
    {
        $reference = $request->input('reference');

        if (is_string($reference) && $reference !== '') {
            $model = RecordReference::open($reference, $request, DescriptorAudience::Comments, $this->registry->resolve(...))['record'];

            RecordReference::authorize($model, 'view');

            return $model;
        }

        abort_if(RecordReference::required('comments'), 403, __('kinetix.table_write_forbidden'));

        $class = $this->registry->resolve((string) $request->input('commentable_type'));
        abort_if($class === null, 404);

        $model = $class::query()->find($request->input('commentable_id'));
        abort_if(! $model instanceof Model, 404);

        // A raw type + id names any record of the class, so under teams only a
        // policy can tell whether this one belongs to the caller's team.
        RecordReference::authorize($model, 'view', policyRequired: (bool) config('kinetix.teams', false));

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
