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
use Illuminate\Support\Facades\Gate;
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
 * 5. Authorization — each surviving record is authorized against the host's
 *    policy (the explicit `writeAbility()`, or `update` when the model has a
 *    policy), one by one, before the handler sees it.
 *
 * Only then is the authorized {@see Collection} handed to the action's
 * {@see BulkAction::handle()}. The whole run is wrapped in a transaction so a
 * handler that throws midway leaves nothing half-applied.
 */
class BulkActionController
{
    public function __invoke(Request $request): JsonResponse
    {
        $descriptor = $this->descriptor($request);

        if ($descriptor instanceof JsonResponse) {
            return $descriptor;
        }

        $name = (string) $request->input('action');

        // Only an action named in the sealed map may run — and only via the
        // class the table registered for it.
        $class = $descriptor['bulk'][$name] ?? null;

        if (! is_string($class) || ! is_subclass_of($class, BulkAction::class)) {
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

        // Resolve every id through the table's scope in ONE query; ids outside
        // it simply don't come back.
        $records = $this->baseQuery($descriptor)->whereKey($ids)->get();

        // Authorize each surviving record; a single denial fails the whole
        // batch closed rather than silently acting on the allowed subset.
        foreach ($records as $record) {
            if (! $this->authorize($descriptor, $record)) {
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
        $action = $class::make($name);

        DB::transaction(static function () use ($action, $records): void {
            $action->handle($records);
        });

        return response()->json(['status' => 'success', 'affected' => $records->count()]);
    }

    /**
     * Decrypt and validate the table's signed bulk descriptor.
     *
     * @return array{model: class-string<Model>, bulk: array<string, class-string<BulkAction>>, resource: class-string<resource>|null, scope: array<array-key, mixed>, relation: array<string, mixed>|null, ability: string|null}|JsonResponse
     */
    protected function descriptor(Request $request): array|JsonResponse
    {
        try {
            $payload = Crypt::decrypt((string) $request->input('descriptor'));
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

        // Keep only string name → BulkAction-subclass pairs.
        $normalizedBulk = [];

        if (is_array($bulk)) {
            foreach ($bulk as $actionName => $actionClass) {
                if (is_string($actionName) && is_string($actionClass) && is_subclass_of($actionClass, BulkAction::class)) {
                    $normalizedBulk[$actionName] = $actionClass;
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

    /**
     * Authorize one record through the host's policy — the explicit ability
     * from writeAbility(), or `update` whenever the model has a policy at all.
     *
     * @param array{model: class-string<Model>, ability: string|null} $descriptor
     */
    protected function authorize(array $descriptor, Model $record): bool
    {
        $ability = $descriptor['ability']
            ?? (Gate::getPolicyFor($descriptor['model']) !== null ? 'update' : null);

        if ($ability === null) {
            return true;
        }

        return Gate::forUser(request()->user())->allows($ability, $record);
    }
}
