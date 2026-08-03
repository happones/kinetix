<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;

/**
 * Persists individual TableRepeater rows for autosave mode. Every request is
 * guarded by the field's signed descriptor (parent model + relation + writable
 * column allowlist) so the client can only create/update/delete rows on the
 * bound relation, writing only the declared columns.
 */
class TableRepeaterController
{
    public function store(Request $request): JsonResponse
    {
        [$relation, $columns] = $this->resolve($request);

        $row = $relation->create($this->values($request, $columns));

        return response()->json(['status' => 'success', 'id' => $row->getKey()]);
    }

    public function update(Request $request): JsonResponse
    {
        [$relation, $columns] = $this->resolve($request);

        $row = $relation->getQuery()->whereKey($request->input('id'))->first();
        abort_if($row === null, 404, (string) __('kinetix.repeater_row_not_found'));

        $row->fill($this->values($request, $columns))->save();

        return response()->json(['status' => 'success']);
    }

    public function destroy(Request $request): JsonResponse
    {
        [$relation] = $this->resolve($request);

        $relation->getQuery()->whereKey($request->input('id'))->delete();

        return response()->json(['status' => 'success']);
    }

    /**
     * Decode the signed descriptor, load the parent, and return its relation +
     * the writable-column allowlist.
     *
     * @return array{0: HasOneOrMany, 1: array<int, string>}
     */
    protected function resolve(Request $request): array
    {
        try {
            $payload = Crypt::decrypt((string) $request->input('token'));
        } catch (\Throwable) {
            abort(400, 'Invalid descriptor.');
        }

        $parentClass  = is_array($payload) ? ($payload['parent'] ?? null) : null;
        $relationName = is_array($payload) ? ($payload['relation'] ?? null) : null;
        $columns      = is_array($payload) ? ($payload['columns'] ?? []) : [];

        abort_unless(
            is_string($parentClass) && class_exists($parentClass) && is_subclass_of($parentClass, Model::class),
            400,
            'Invalid parent model.',
        );
        abort_unless(is_string($relationName) && $relationName !== '', 400, 'Invalid relation.');

        // The descriptor is minted for one user and expires, so it can't be lifted
        // from another user's page and replayed.
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

        // Editing a repeater row is editing the parent record, so the parent's own
        // policy governs it — the same rule the record modals and kanban use.
        if (Gate::getPolicyFor($parent::class) !== null) {
            abort_unless(
                Gate::forUser($request->user())->allows('update', $parent),
                403,
                'This action is unauthorized.',
            );
        }

        abort_unless(method_exists($parent, $relationName), 400, 'Unknown relation.');
        $relation = $parent->{$relationName}();
        abort_unless($relation instanceof HasOneOrMany, 400, 'Unsupported relation type.');

        return [$relation, is_array($columns) ? array_values($columns) : []];
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
