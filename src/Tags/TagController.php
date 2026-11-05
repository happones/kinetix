<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tags;

use Happones\Kinetix\Support\DescriptorAudience;
use Happones\Kinetix\Support\KinetixTeams;
use Happones\Kinetix\Support\RecordReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service tagging: list a taggable's tags, autocomplete from existing tags,
 * and sync a model's tags. The taggable model must be allowlisted via
 * KinetixTags::for([...]) and use the HasKinetixTags trait, and is named by a
 * signed reference (KinetixTags::reference($model)). A host "update" policy on
 * the model is honored for writes, "view" for reads.
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

    /**
     * Resolve + authorize the taggable from the request: a signed `reference`
     * ({@see KinetixTags::reference()}, which must be editable for writes),
     * or the legacy `taggable_type` + `taggable_id` when
     * `tags.require_reference` allows it.
     */
    protected function taggable(Request $request, bool $write = false): Model
    {
        $this->user($request);

        $ability   = $write ? 'update' : 'view';
        $reference = $request->input('reference');

        if (is_string($reference) && $reference !== '') {
            ['record' => $model, 'claims' => $claims] = RecordReference::open($reference, $request, DescriptorAudience::Tags, $this->registry->resolve(...));

            abort_if($write && ($claims['editable'] ?? false) !== true, 403, __('kinetix.table_write_forbidden'));

            RecordReference::authorize($model, $ability);

            return $model;
        }

        abort_if(RecordReference::required('tags'), 403, __('kinetix.table_write_forbidden'));

        $class = $this->registry->resolve((string) $request->input('taggable_type'));
        abort_if($class === null, 404);

        $model = $class::query()->find($request->input('taggable_id'));
        abort_if(! $model instanceof Model, 404);

        // A raw type + id names any record of the class, so under teams only a
        // policy can tell whether this one belongs to the caller's team.
        RecordReference::authorize($model, $ability, policyRequired: (bool) config('kinetix.teams', false));

        return $model;
    }

    protected function teamId(Request $request): int|string|null
    {
        if (! config('kinetix.teams', false)) {
            return null;
        }

        // Translates a slug/uuid route segment to the team's primary key and
        // verifies the user's membership (404 otherwise).
        return KinetixTeams::currentTeamKey($request);
    }
}
