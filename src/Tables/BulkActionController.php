<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables;

use Happones\Kinetix\Actions\BulkAction;
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Support\DescriptorRejection;
use Happones\Kinetix\Support\SignedDescriptor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs a table's server-side bulk actions ({@see BulkAction}) over the selected
 * rows, defended exactly like the inline-edit/reorder write path:
 *
 * 1. Binding — the signed descriptor records the user + team it was minted for
 *    ({@see SignedDescriptor}); a leaked token is useless to anyone else.
 * 2. Freshness — descriptors expire (`kinetix.tables.token_ttl`).
 * 3. Allowlist — only an action NAMED in the descriptor's sealed name→class map
 *    may run, and only the class the table registered for it. The client can't
 *    name an arbitrary class.
 * 4. Scoping — every id is resolved through the table's own constraints (the
 *    resource query / captured where-scope / parent relation), so an id outside
 *    the table the user was looking at is silently dropped, never acted on.
 * 5. Authorization — each surviving record is authorized one by one before
 *    the handler sees it: against the action's own `authorize('ability')`
 *    when it has one (else the table's `writeAbility()`, or `update` when the
 *    model has a policy), and — for an action gated by a record-dependent
 *    `visible()`/`hidden()` closure — only on records that closure allowed
 *    when the table rendered ({@see SealedAction}). A selection kept across
 *    pages sends the descriptors of the pages its rows were picked on
 *    (`descriptors`); each one minted for the same user, table and action
 *    adds the records it allowed.
 *
 * Only then is the authorized {@see Collection} handed to the action's
 * {@see BulkAction::handle()}. The whole run is wrapped in a transaction so a
 * handler that throws midway leaves nothing half-applied.
 */
class BulkActionController
{
    /** How many other pages' descriptors one run reads. */
    private const MAX_OTHER_PAGES = 50;

    public function __invoke(Request $request): JsonResponse
    {
        $descriptor = $this->descriptor((string) $request->input('descriptor'), $request);

        if ($descriptor instanceof JsonResponse) {
            return $descriptor;
        }

        $name = (string) $request->input('action');

        // Only an action named in the sealed map may run — and only via the
        // class the table registered for it.
        $sealed = $descriptor['bulk'][$name] ?? null;

        if ($sealed === null) {
            return response()->json([
                'status'  => 'error',
                'message' => __('kinetix.table_bulk_action_not_allowed'),
            ], 403);
        }

        // Reject nested arrays outright, like reorder does.
        $ids = array_values(array_filter(
            (array) $request->input('ids', []),
            static fn (mixed $id): bool => is_scalar($id),
        ));

        if ($ids === []) {
            return response()->json(['status' => 'success', 'affected' => 0]);
        }

        // Cap the batch, reusing the reorder ceiling (same DoS shape: resolve +
        // act on an unbounded number of rows in one request).
        $max = (int) config('kinetix.tables.reorder_max', 1000);

        if ($max > 0 && count($ids) > $max) {
            return response()->json([
                'status'  => 'error',
                'message' => __('kinetix.table_reorder_too_large'),
            ], 422);
        }

        $sealed = $sealed->withGrants($this->grantsFromOtherPages($request, $descriptor, $name, $sealed));

        // Resolve every id through the table's scope in ONE query; ids outside
        // it simply don't come back.
        $records = $this->baseQuery($descriptor)->whereKey($ids)->get();

        // Authorize each surviving record; a single denial fails the whole
        // batch closed rather than silently acting on the allowed subset.
        foreach ($records as $record) {
            if (! $sealed->authorizes($record, $descriptor['model'], $descriptor['ability'])) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('kinetix.table_write_forbidden'),
                ], 403);
            }
        }

        if ($records->isEmpty()) {
            return response()->json(['status' => 'success', 'affected' => 0]);
        }

        /** @var BulkAction $action */
        $action = $sealed->instantiate($name);

        DB::transaction(static function () use ($action, $records): void {
            $action->handle($records);
        });

        return response()->json(['status' => 'success', 'affected' => $records->count()]);
    }

    /**
     * The records a gated action was allowed on by the other pages the
     * selection was made on: their descriptors, when each is valid and was
     * minted for the same table and the same configured action. Anything
     * else adds nothing, so its rows stay refused.
     *
     * @param  array{model: class-string<Model>, bulk: array<string, SealedAction>, resource: class-string<resource>|null, scope: array<array-key, mixed>, relation: array<string, mixed>|null, ability: string|null} $descriptor
     * @return list<string>
     */
    protected function grantsFromOtherPages(Request $request, array $descriptor, string $name, SealedAction $sealed): array
    {
        if ($sealed->grants === null) {
            return [];
        }

        $tokens = array_slice(array_values(array_unique(array_filter(
            (array) $request->input('descriptors', []),
            static fn (mixed $token): bool => is_string($token) && $token !== '',
        ))), 0, self::MAX_OTHER_PAGES);

        $grants = [];

        foreach ($tokens as $token) {
            $other = $this->descriptor($token, $request);

            if ($other instanceof JsonResponse) {
                continue;
            }

            $otherSealed = $other['bulk'][$name] ?? null;

            if (
                $otherSealed            === null
                || $otherSealed->grants === null
                || ! $otherSealed->isSameActionAs($sealed)
                || array_diff_key($other, ['bulk' => true]) !== array_diff_key($descriptor, ['bulk' => true])
            ) {
                continue;
            }

            array_push($grants, ...$otherSealed->grants);
        }

        return $grants;
    }

    /**
     * Decrypt and validate a table's signed bulk descriptor.
     *
     * @return array{model: class-string<Model>, bulk: array<string, SealedAction>, resource: class-string<resource>|null, scope: array<array-key, mixed>, relation: array<string, mixed>|null, ability: string|null}|JsonResponse
     */
    protected function descriptor(string $token, Request $request): array|JsonResponse
    {
        try {
            $payload = Crypt::decrypt($token);
        } catch (Throwable) {
            return response()->json([
                'status'  => 'error',
                'message' => __('kinetix.table_invalid_signature'),
            ], 400);
        }

        if (! is_array($payload)) {
            return response()->json([
                'status'  => 'error',
                'message' => __('kinetix.table_invalid_signature'),
            ], 400);
        }

        $modelClass = $payload['model'] ?? null;

        if (! is_string($modelClass) || ! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            return response()->json([
                'status'  => 'error',
                'message' => __('kinetix.table_invalid_model'),
            ], 400);
        }

        $rejection = SignedDescriptor::rejection($payload, $request);

        if ($rejection !== null) {
            return response()->json([
                'status'  => 'error',
                'message' => $rejection === DescriptorRejection::Expired
                    ? __('kinetix.table_descriptor_expired')
                    : __('kinetix.table_write_forbidden'),
            ], 403);
        }

        $resource = $payload['resource'] ?? null;

        if (! is_string($resource) || ! class_exists($resource) || ! is_subclass_of($resource, Resource::class)) {
            $resource = null;
        }

        $bulk     = $payload['bulk']     ?? [];
        $scope    = $payload['scope']    ?? [];
        $ability  = $payload['ability']  ?? null;
        $relation = $payload['relation'] ?? null;

        // Keep only well-formed name → sealed BulkAction entries.
        $normalizedBulk = [];

        if (is_array($bulk)) {
            foreach ($bulk as $actionName => $entry) {
                $sealedAction = SealedAction::fromPayload($entry, BulkAction::class);

                if (is_string($actionName) && $sealedAction !== null) {
                    $normalizedBulk[$actionName] = $sealedAction;
                }
            }
        }

        return [
            'model'    => $modelClass,
            'bulk'     => $normalizedBulk,
            'resource' => $resource,
            'scope'    => is_array($scope) ? $scope : [],
            'relation' => is_array($relation) ? $relation : null,
            'ability'  => is_string($ability) ? $ability : null,
        ];
    }

    /**
     * The table's constrained base query (resource query / captured scope /
     * parent relation) — identical to the write controller's, so a bulk action
     * can only ever touch records the table itself could show.
     *
     * @param  array{model: class-string<Model>, resource: class-string<resource>|null, scope: array<array-key, mixed>, relation?: array<string, mixed>|null} $descriptor
     * @return Builder<Model>
     */
    protected function baseQuery(array $descriptor): Builder
    {
        $relation = $descriptor['relation'] ?? null;

        if (is_array($relation)) {
            return $this->relationQuery($relation, $descriptor['model']);
        }

        $resource = $descriptor['resource'];

        $query = $resource !== null
            ? $resource::getEloquentQuery()
            : $descriptor['model']::query();

        foreach ($descriptor['scope'] as $column => $value) {
            if (! is_string($column)) {
                continue;
            }

            $value === null
                ? $query->whereNull($column)
                : $query->where($column, $value);
        }

        return $query;
    }

    /**
     * The parent-bound query for a relation table's bulk action.
     *
     * @param  array<string, mixed> $relation
     * @param  class-string<Model>  $modelClass
     * @return Builder<Model>
     */
    protected function relationQuery(array $relation, string $modelClass): Builder
    {
        $parentClass  = $relation['parent'] ?? null;
        $relationName = $relation['name']   ?? null;

        abort_unless(
            is_string($parentClass) && class_exists($parentClass) && is_subclass_of($parentClass, Model::class),
            400,
            'Invalid parent model.',
        );
        abort_unless(
            is_string($relationName) && $relationName !== '' && method_exists($parentClass, $relationName),
            400,
            'Invalid relation.',
        );

        $parent = $parentClass::query()->whereKey($relation['key'] ?? null)->first();
        abort_if($parent === null, 404, (string) __('kinetix.table_record_not_found'));

        $relationObject = $parent->{$relationName}();

        abort_unless($relationObject instanceof Relation, 400, 'Invalid relation.');
        abort_unless($relationObject->getRelated()::class === $modelClass, 400, 'Relation model mismatch.');

        return $relationObject->getQuery()->select($relationObject->getRelated()->qualifyColumn('*'));
    }
}
