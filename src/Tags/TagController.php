<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tags;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Self-service tagging: list a taggable's tags, autocomplete from existing tags,
 * and sync a model's tags. The taggable model must be allowlisted via
 * KinetixTags::for([...]) and use the HasKinetixTags trait. A host "update"
 * policy on the model is honored for writes.
 */
class TagController
{
    public function __construct(
        protected TagManager $manager,
        protected TagRegistry $registry,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $taggable = $this->taggable($request);

        return response()->json(['tags' => $this->manager->for($taggable)]);
    }

    public function suggest(Request $request): JsonResponse
    {
        $this->user($request);

        return response()->json([
            'tags' => $this->manager->suggest(
                (string) $request->query('q', ''),
                $this->teamId($request),
            ),
        ]);
    }

    public function sync(Request $request): JsonResponse
    {
        $taggable = $this->taggable($request, write: true);

        $validated = $request->validate([
            'tags'   => ['array'],
            'tags.*' => ['string', 'max:50'],
        ]);

        $this->manager->sync($taggable, $validated['tags'] ?? [], $this->teamId($request));

        return response()->json(['tags' => $this->manager->for($taggable)]);
    }

    protected function user(Request $request): Model
    {
        $user = $request->user();

        abort_if(! $user instanceof Model, 401);

        return $user;
    }

    protected function taggable(Request $request, bool $write = false): Model
    {
        $this->user($request);

        $class = $this->registry->resolve((string) $request->input('taggable_type'));
        abort_if($class === null, 404);

        $model = $class::query()->find($request->input('taggable_id'));
        abort_if(! $model instanceof Model, 404);

        // Honor a host policy: 'update' for writes, 'view' for reads.
        if (Gate::getPolicyFor($model) !== null && Gate::denies($write ? 'update' : 'view', $model)) {
            abort(403);
        }

        return $model;
    }

    protected function teamId(Request $request): int|string|null
    {
        if (! config('kinetix.teams', false)) {
            return null;
        }

        return $request->route('current_team');
    }
}
