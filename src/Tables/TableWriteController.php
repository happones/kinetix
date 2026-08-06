<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables;

use Happones\Kinetix\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Powers a table's two write endpoints: inline cell edits (ToggleColumn,
 * TextInputColumn, SelectColumn) and drag-and-drop reordering.
 *
 * Both requests carry the table's signed descriptor — an encrypted payload the
 * client can neither read nor forge — and the endpoint trusts only what it
 * decrypts. The descriptor is the whole basis of trust, so it is defended on
 * four axes, in this order:
 *
 * 1. Binding — the descriptor records the user it was minted for. A token
 *    leaked from an admin's payload is useless to anyone else, so a lower
 *    privileged user cannot replay the wider `columns` allowlist an admin's
 *    table embedded.
 * 2. Freshness — descriptors expire (see `kinetix.tables.token_ttl`), bounding
 *    the replay window of a token captured from a long-lived page.
 * 3. Scoping — the record is resolved through the table's own constraints (the
 *    resource query when the table has one, otherwise the base query's simple
 *    where clauses, captured at mint time), so a record outside the table the
 *    user was actually looking at is a 404 rather than a write.
 * 4. Authorization — the host's policy decides, exactly as `kanban-move` and
 *    the record modals do: the explicit ability from `writeAbility()`, or
 *    `update` whenever the model has a policy. Without a policy nothing is
 *    enforced here and the host owns access — but scoping still applies.
 *
 * Column allowlisting is layered on top for cell edits: only columns the table
 * declared editable may be written, so a privileged attribute (`is_admin`)
 * cannot be tampered with even by a user who legitimately holds the token.
 */
class TableWriteController
{
    /**
     * Write a single value to a single editable column of one record.
     */
    public function cellUpdate(Request $request): JsonResponse
    {
        $descriptor = $this->descriptor($request);

        if ($descriptor instanceof JsonResponse) {
            return $descriptor;
        }

        $column = (string) $request->input('column');

        // Only columns explicitly declared as editable on the table may be
        // written, preventing tampering with arbitrary (e.g. privileged)
        // attributes even when the token itself is legitimate.
        $editableColumns = $descriptor['columns'];

        if (! in_array($column, $editableColumns, true)) {
            return response()->json([
                'status'  => 'error',
                'message' => __('kinetix.table_column_not_editable'),
            ], 403);
        }

        $record = $this->findRecord($descriptor, $request->input('recordId'));

        if ($record === null) {
            return response()->json([
                'status'  => 'error',
                'message' => __('kinetix.table_record_not_found'),
            ], 404);
        }

        if (! $this->authorize($descriptor, $record)) {
            return response()->json([
                'status'  => 'error',
                'message' => __('kinetix.table_write_forbidden'),
            ], 403);
        }

        $record->{$column} = $request->input('value');
        $record->save();

        return response()->json(['status' => 'success']);
    }

    /**
     * Persist a new manual order for the given record ids.
     */
    public function reorder(Request $request): JsonResponse
    {
        $descriptor = $this->descriptor($request);

        if ($descriptor instanceof JsonResponse) {
            return $descriptor;
        }

        // The reorder column is baked into the signed descriptor only when the
        // table opted in via reorderable(); otherwise reject.
        $reorderColumn = $descriptor['reorder'];

        if (! is_string($reorderColumn) || $reorderColumn === '') {
            return response()->json([
                'status'  => 'error',
                'message' => __('kinetix.table_not_reorderable'),
            ], 403);
        }

        // Reject nested arrays outright: `whereKey([[1,2,3]])` would otherwise
        // mass-assign one position to a whole set of records.
        $ids = array_values(array_filter(
            (array) $request->input('ids', []),
            static fn (mixed $id): bool => is_scalar($id),
        ));

        if ($ids === []) {
            return response()->json(['status' => 'success']);
        }

        // Resolve every record through the table's scope first, so ids outside
        // it are silently dropped rather than reordered, then authorize each.
        $positions = [];

        foreach ($ids as $position => $id) {
            $record = $this->findRecord($descriptor, $id);

            if ($record === null) {
                continue;
            }

            if (! $this->authorize($descriptor, $record)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('kinetix.table_write_forbidden'),
                ], 403);
            }

            $positions[] = [$record, $position + 1];
        }

        // Save through the model (not a query-builder update) so the host's
        // observers and audit-log listeners still fire, in one transaction so a
        // partial reorder can't be left behind.
        DB::transaction(function () use ($positions, $reorderColumn): void {
            foreach ($positions as [$record, $position]) {
                $record->{$reorderColumn} = $position;
                $record->save();
            }
        });

        return response()->json(['status' => 'success']);
    }

    /**
     * Decrypt and validate the table's signed descriptor, returning either the
     * normalized payload or the JSON error response to send back.
     *
     * @return array{model: class-string<Model>, columns: array<int, string>, reorder: string|null, resource: class-string<resource>|null, scope: array<array-key, mixed>, relation: array<string, mixed>|null, ability: string|null}|JsonResponse
     */
    protected function descriptor(Request $request): array|JsonResponse
    {
        try {
            $payload = Crypt::decrypt((string) $request->input('model'));
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

        // A descriptor is minted for one user. Anyone else presenting it is
        // replaying a leaked token — and would otherwise inherit the wider
        // editable-columns allowlist of whoever it was minted for.
        $mintedFor = $payload['user'] ?? null;

        if ($mintedFor !== null && (string) $mintedFor !== (string) $request->user()?->getAuthIdentifier()) {
            return response()->json([
                'status'  => 'error',
                'message' => __('kinetix.table_write_forbidden'),
            ], 403);
        }

        $expiresAt = $payload['expires'] ?? null;

        if (is_int($expiresAt) && $expiresAt < now()->getTimestamp()) {
            return response()->json([
                'status'  => 'error',
                'message' => __('kinetix.table_descriptor_expired'),
            ], 403);
        }

        $resource = $payload['resource'] ?? null;

        if (! is_string($resource) || ! class_exists($resource) || ! is_subclass_of($resource, Resource::class)) {
            $resource = null;
        }

        $columns  = $payload['columns']  ?? [];
        $scope    = $payload['scope']    ?? [];
        $ability  = $payload['ability']  ?? null;
        $relation = $payload['relation'] ?? null;

        return [
            'model'    => $modelClass,
            'columns'  => is_array($columns) ? array_values(array_filter($columns, is_string(...))) : [],
            'reorder'  => is_string($payload['reorder'] ?? null) ? $payload['reorder'] : null,
            'resource' => $resource,
            'scope'    => is_array($scope) ? $scope : [],
            'relation' => is_array($relation) ? $relation : null,
            'ability'  => is_string($ability) ? $ability : null,
        ];
    }

    /**
     * Resolve one record through the table's own constraints, so a record the
     * table could never have shown is never writable through its descriptor.
     *
     * @param array{model: class-string<Model>, resource: class-string<\Happones\Kinetix\Resources\Resource>|null, scope: array<array-key, mixed>} $descriptor
     */
    protected function findRecord(array $descriptor, mixed $id): ?Model
    {
        // An array id would make find() return a Collection; reject it here
        // rather than letting a type error surface as a 500.
        if (! is_scalar($id)) {
            return null;
        }

        $query = $this->baseQuery($descriptor);

        return $query->whereKey($id)->first();
    }

    /**
     * The table's constrained base query: the resource's own query when the
     * table belongs to one, otherwise the model narrowed by the simple where
     * clauses captured when the descriptor was minted.
     *
     * @param  array{model: class-string<Model>, resource: class-string<resource>|null, scope: array<array-key, mixed>, relation?: array<string, mixed>|null} $descriptor
     * @return Builder<Model>
     */
    protected function baseQuery(array $descriptor): Builder
    {
        // A relation-bound table (a relation manager) resolves records through
        // the PARENT's relationship — the one scope a captured where-list
        // can't express for BelongsToMany (its equality lives on the pivot
        // table, unknown to a join-less model query).
        $relation = $descriptor['relation'] ?? null;

        if (is_array($relation)) {
            return $this->relationQuery($relation, $descriptor['model']);
        }

        $resource = $descriptor['resource'];

        $query = $resource !== null
            ? $resource::getEloquentQuery()
            : $descriptor['model']::query();

        // The captured scope narrows EVEN a resource-backed query. A relation
        // manager's table captures the parent FK here; replacing it with the
        // bare resource query would silently widen writes from "this parent's
        // children" to "any record of the resource".
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
     * The parent-bound query for a relation table's writes. Everything is
     * validated against the signed descriptor — the client named nothing.
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

        abort_unless(
            $relationObject instanceof Relation,
            400,
            'Invalid relation.',
        );

        $related = $relationObject->getRelated();

        abort_unless($related::class === $modelClass, 400, 'Relation model mismatch.');

        // Qualified select: BelongsToMany joins the pivot, and a bare * would
        // let pivot columns clobber the related model's at hydration.
        return $relationObject->getQuery()->select($related->qualifyColumn('*'));
    }

    /**
     * Authorize the write through the host's policy — the explicit ability from
     * writeAbility(), or `update` whenever the model has a policy at all.
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
