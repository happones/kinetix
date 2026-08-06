<?php

declare(strict_types=1);

namespace Happones\Kinetix\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;

/**
 * BelongsToMany attach/detach for relation managers. Every request is guarded
 * by the manager's signed descriptor (parent model + key + relation, bound to
 * the user it was minted for, expiring) — the same proven contract the
 * TableRepeater autosave endpoints use — plus the PARENT's `update` policy:
 * attaching/detaching children is editing the parent.
 */
class RelationManagerController
{
    /**
     * Records that can still be attached: the related model minus what's
     * already attached, searchable on the descriptor's title attribute.
     */
    public function attachable(Request $request): JsonResponse
    {
        [$relation, $payload] = $this->resolve($request);

        $related = $relation->getRelated();
        $title   = $this->titleColumn($payload, $related);

        $query = $related->newQuery()
            ->whereNotIn(
                $related->getQualifiedKeyName(),
                $relation->pluck($related->getQualifiedKeyName()),
            );

        $search = trim((string) $request->input('search', ''));

        if ($search !== '') {
            $query->where($title, 'like', "%{$search}%");
        }

        $options = $query
            ->orderBy($title)
            ->limit(50)
            ->get()
            ->map(fn (Model $record): array => [
                'id'    => $record->getKey(),
                'label' => (string) $record->getAttribute($title),
            ])
            ->values();

        return response()->json(['options' => $options]);
    }

    public function attach(Request $request): JsonResponse
    {
        [$relation] = $this->resolve($request);

        $ids = $this->ids($request);
        abort_if($ids === [], 422, 'Nothing to attach.');

        // Only ids that actually exist on the related model — attach() would
        // happily insert pivot rows for ghosts on DBs without FK enforcement.
        $related = $relation->getRelated();
        $valid   = $related->newQuery()->whereKey($ids)->pluck($related->getKeyName())->all();

        $relation->syncWithoutDetaching($valid);

        return response()->json(['status' => 'success', 'attached' => count($valid)]);
    }

    public function detach(Request $request): JsonResponse
    {
        [$relation] = $this->resolve($request);

        $ids = $this->ids($request);
        abort_if($ids === [], 422, 'Nothing to detach.');

        $relation->detach($ids);

        return response()->json(['status' => 'success']);
    }

    /**
     * The record id(s) being acted on: an explicit `ids` array (bulk / attach
     * modal) or the single `record` a row action merged into the body.
     *
     * @return array<int, int|string>
     */
    protected function ids(Request $request): array
    {
        $ids = array_values(array_filter(
            (array) $request->input('ids', []),
            static fn (mixed $id): bool => is_scalar($id),
        ));

        if ($ids === [] && is_scalar($request->input('record.id'))) {
            $ids = [$request->input('record.id')];
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function titleColumn(array $payload, Model $related): string
    {
        $column = $payload['title'] ?? null;

        return is_string($column) && $column !== '' ? $column : $related->getKeyName();
    }

    /**
     * Decode the signed descriptor, load the parent, authorize through its
     * policy, and return its BelongsToMany relation + the payload.
     *
     * @return array{0: BelongsToMany, 1: array<string, mixed>}
     */
    protected function resolve(Request $request): array
    {
        try {
            $payload = Crypt::decrypt((string) $request->input('descriptor'));
        } catch (\Throwable) {
            abort(400, 'Invalid descriptor.');
        }

        $parentClass  = is_array($payload) ? ($payload['parent'] ?? null) : null;
        $relationName = is_array($payload) ? ($payload['relation'] ?? null) : null;

        abort_unless(
            is_string($parentClass) && class_exists($parentClass) && is_subclass_of($parentClass, Model::class),
            400,
            'Invalid parent model.',
        );
        abort_unless(is_string($relationName) && $relationName !== '', 400, 'Invalid relation.');

        // The descriptor is minted for one user and expires, so it can't be
        // lifted from another user's page and replayed.
        $mintedFor = $payload['user'] ?? null;
        abort_if(
            $mintedFor !== null && (string) $mintedFor !== (string) $request->user()?->getAuthIdentifier(),
            403,
            (string) __('kinetix.table_write_forbidden'),
        );

        $expiresAt = $payload['expires'] ?? null;
        abort_if(
            is_int($expiresAt) && $expiresAt < now()->getTimestamp(),
            403,
            (string) __('kinetix.form_session_expired'),
        );

        $parent = $parentClass::query()->whereKey($payload['key'] ?? null)->first();
        abort_if($parent === null, 404, 'Parent record not found.');

        // Attaching/detaching children is editing the parent — its policy rules.
        if (Gate::getPolicyFor($parent::class) !== null) {
            abort_unless(
                Gate::forUser($request->user())->allows('update', $parent),
                403,
                'This action is unauthorized.',
            );
        }

        abort_unless(method_exists($parent, $relationName), 400, 'Unknown relation.');
        $relation = $parent->{$relationName}();
        abort_unless($relation instanceof BelongsToMany, 400, 'Attach/detach requires a BelongsToMany relation.');

        // $payload is proven array by the guards above (a non-array yields a
        // null parent class, which aborts).
        return [$relation, $payload];
    }
}
