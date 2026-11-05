<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms;

use Happones\Kinetix\Support\DescriptorAudience;
use Happones\Kinetix\Support\DescriptorRejection;
use Happones\Kinetix\Support\EloquentRelation;
use Happones\Kinetix\Support\SignedDescriptor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Persists individual TableRepeater rows for autosave mode. Every request is
 * guarded by the field's signed descriptor (parent model + relation + writable
 * column allowlist) so the client can only create/update/delete rows on the
 * bound relation, writing only the declared columns.
 *
 * Editing a row is editing the parent, so the parent's `update` policy
 * governs every request; the row's own policy, when its model has one, also
 * has to allow `create`, `update` or `delete`. Rows are loaded and saved or
 * deleted as models, so their events and observers fire.
 */
class TableRepeaterController
{
    public function store(Request $request): JsonResponse
    {
        [$relation, $columns] = $this->resolve($request);

        $this->authorizeRow($request, 'create', $relation->getRelated()::class);

        $row = $relation->create($this->values($request, $columns));

        return response()->json(['status' => 'success', 'id' => $row->getKey()]);
    }

    public function update(Request $request): JsonResponse
    {
        [$relation, $columns] = $this->resolve($request);

        $row = $this->row($relation, $request);

        $this->authorizeRow($request, 'update', $row);

        $row->fill($this->values($request, $columns))->save();

        return response()->json(['status' => 'success']);
    }

    public function destroy(Request $request): JsonResponse
    {
        [$relation] = $this->resolve($request);

        $row = $this->row($relation, $request);

        $this->authorizeRow($request, 'delete', $row);

        $row->delete();

        return response()->json(['status' => 'success']);
    }

    /**
     * Decode the signed descriptor, load the parent, and return its relation +
     * the writable-column allowlist.
     *
     * @return array{0: HasOneOrMany<Model, Model, mixed>, 1: array<int, string>}
     */
    protected function resolve(Request $request): array
    {
        $payload = SignedDescriptor::open((string) $request->input('token'));

        abort_if($payload === null, 400, 'Invalid descriptor.');

        // The descriptor is bound to the user and team it was minted for,
        // expires, and names this endpoint as its audience
        // ({@see SignedDescriptor}), so it can't be lifted from another user's
        // page — or another team's, or another endpoint's — and replayed.
        $rejection = SignedDescriptor::rejection($payload, $request, DescriptorAudience::TableRepeater);
        abort_if(
            $rejection !== null,
            403,
            $rejection === DescriptorRejection::Expired
                ? (string) __('kinetix.form_session_expired')
                : (string) __('kinetix.table_write_forbidden'),
        );

        $parentClass  = $payload['parent']   ?? null;
        $relationName = $payload['relation'] ?? null;
        $columns      = $payload['columns']  ?? null;

        abort_unless(
            is_string($parentClass) && class_exists($parentClass) && is_subclass_of($parentClass, Model::class),
            400,
            'Invalid parent model.',
        );
        abort_unless(is_string($relationName) && $relationName !== '', 400, 'Invalid relation.');
        abort_unless(is_array($columns), 400, 'Invalid descriptor.');

        $parent = $parentClass::query()->whereKey($payload['key'] ?? null)->first();
        abort_if($parent === null, 404, 'Parent record not found.');

        // Editing a repeater row is editing the parent record, so the parent's own
        // policy governs it — the same rule the record modals and kanban use.
        if (Gate::getPolicyFor($parent::class) !== null) {
            abort_unless(
                Gate::forUser($request->user())->allows('update', $parent),
                403,
                'This action is unauthorized.',
            );
        }

        $relation = EloquentRelation::resolve($parent, $relationName);
        abort_unless($relation instanceof HasOneOrMany, 400, 'Unsupported relation type.');

        return [$relation, array_values(array_filter($columns, 'is_string'))];
    }

    /**
     * The row the request names, loaded through the bound relation.
     *
     * @param HasOneOrMany<Model, Model, mixed> $relation
     */
    protected function row(HasOneOrMany $relation, Request $request): Model
    {
        $id = $request->input('id');

        abort_unless(is_scalar($id), 404, (string) __('kinetix.repeater_row_not_found'));

        $row = $relation->getQuery()->whereKey($id)->first();
        abort_unless($row instanceof Model, 404, (string) __('kinetix.repeater_row_not_found'));

        return $row;
    }

    /**
     * Enforce the row model's own policy, when it has one.
     *
     * @param Model|class-string<Model> $row
     */
    protected function authorizeRow(Request $request, string $ability, Model|string $row): void
    {
        if (Gate::getPolicyFor($row) === null) {
            return;
        }

        abort_unless(
            Gate::forUser($request->user())->allows($ability, $row),
            403,
            'This action is unauthorized.',
        );
    }

    /**
     * The request payload filtered to the writable columns only.
     *
     * @param  array<int, string>   $columns
     * @return array<string, mixed>
     */
    protected function values(Request $request, array $columns): array
    {
        return array_intersect_key(
            (array) $request->input('values', []),
            array_flip($columns),
        );
    }
}
