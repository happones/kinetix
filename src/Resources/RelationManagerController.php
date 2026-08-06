<?php

declare(strict_types=1);

namespace Happones\Kinetix\Resources;

use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Infolists\Infolist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;

/**
 * Parent-bound endpoints for relation managers: modal CRUD
 * (create/edit/view/delete through the PARENT's relationship),
 * BelongsToMany attach/detach, and HasMany/MorphMany associate/dissociate.
 *
 * Every request is guarded by the manager's signed descriptor (parent model +
 * key + relation + manager class, bound to the user it was minted for,
 * expiring) — the same proven contract the TableRepeater autosave endpoints
 * use — plus the PARENT's `update` policy (touching children is editing the
 * parent) and, for record CRUD, the CHILD model's own policy when it has one.
 */
class RelationManagerController
{
    // -- Modal CRUD (create/edit/view/delete through the relationship) -------

    /**
     * Fetch a fresh form (create/edit) or infolist (view) for a related record,
     * built by the MANAGER's own form()/infolist().
     */
    public function record(Request $request): JsonResponse
    {
        [$relation, $payload, $parent] = $this->resolve($request, 'many');

        $manager = $this->manager($payload, $parent);
        $mode    = (string) $request->input('mode', 'edit');
        $related = $relation->getRelated();

        if ($mode === 'create') {
            $form = $manager->form(Form::make(new $related)->operation('create'))->fill();

            return response()->json(['form' => $form->toArray()]);
        }

        $record = $this->findRelated($relation, $request->input('id'));

        if ($mode === 'view') {
            $this->authorizeChild($related::class, 'view', $record);

            return response()->json([
                'infolist' => $manager->infolist(Infolist::make($record))->toArray(),
            ]);
        }

        // Default: edit — a fresh, filled form (so concurrent edits aren't lost).
        $this->authorizeChild($related::class, 'update', $record);

        $form = $manager->form(Form::make($record)->operation('edit'))->fill($record);

        return response()->json(['form' => $form->toArray()]);
    }

    /**
     * Create a related record THROUGH the relationship — the parent binding
     * (foreign key / morph columns / pivot row) is stamped server-side, never
     * taken from the payload.
     */
    public function storeRecord(Request $request): RedirectResponse
    {
        [$relation, $payload, $parent] = $this->resolve($request, 'many');

        $manager = $this->manager($payload, $parent);
        $related = $relation->getRelated();

        $this->authorizeChild($related::class, 'create', $related::class);

        $form = $manager->form(Form::make(new $related)->operation('create'));
        $form->validate((array) $request->input('data', []));

        // HasMany/MorphMany stamp the FK (+ morph type); BelongsToMany creates
        // the related record AND attaches it in one step.
        $relation->create($form->getState((array) $request->input('data', [])));

        return back()
            ->with('message', (string) __('kinetix.record_created'))
            ->with('kinetix_toast', (string) __('kinetix.record_created'));
    }

    public function updateRecord(Request $request): RedirectResponse
    {
        [$relation, $payload, $parent] = $this->resolve($request, 'many');

        $manager = $this->manager($payload, $parent);
        $record  = $this->findRelated($relation, $request->input('id'));

        $this->authorizeChild($relation->getRelated()::class, 'update', $record);

        $form = $manager->form(Form::make($record)->operation('edit'));
        $form->validate((array) $request->input('data', []));

        $record->update($form->getState((array) $request->input('data', [])));

        return back()
            ->with('message', (string) __('kinetix.record_updated'))
            ->with('kinetix_toast', (string) __('kinetix.record_updated'));
    }

    public function destroyRecord(Request $request): RedirectResponse
    {
        [$relation] = $this->resolve($request, 'many');

        $record = $this->findRelated($relation, $request->input('id'));

        $this->authorizeChild($relation->getRelated()::class, 'delete', $record);

        // BelongsToMany: drop the pivot row too, or deleting the related
        // record would strand an orphan pivot on DBs without FK cascade.
        if ($relation instanceof BelongsToMany) {
            $relation->detach($record->getKey());
        }

        $record->delete();

        return back()
            ->with('message', (string) __('kinetix.record_deleted'))
            ->with('kinetix_toast', (string) __('kinetix.record_deleted'));
    }

    // -- BelongsToMany attach/detach ------------------------------------------

    /**
     * Records that can still be attached: the related model minus what's
     * already attached, searchable on the descriptor's title attribute.
     */
    public function attachable(Request $request): JsonResponse
    {
        [$relation, $payload] = $this->resolve($request, 'belongsToMany');

        $related = $relation->getRelated();

        $query = $related->newQuery()
            ->whereNotIn(
                $related->getQualifiedKeyName(),
                $relation->pluck($related->getQualifiedKeyName()),
            );

        return $this->optionsResponse($query, $payload, $related, $request);
    }

    public function attach(Request $request): JsonResponse
    {
        [$relation] = $this->resolve($request, 'belongsToMany');

        /** @var BelongsToMany<Model, Model> $relation */
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
        [$relation] = $this->resolve($request, 'belongsToMany');

        /** @var BelongsToMany<Model, Model> $relation */
        $ids = $this->ids($request);
        abort_if($ids === [], 422, 'Nothing to detach.');

        $relation->detach($ids);

        return response()->json(['status' => 'success']);
    }

    // -- HasMany/MorphMany associate/dissociate --------------------------------

    /**
     * Records that can be associated: related records not yet owned by any
     * parent (foreign key IS NULL — Filament's default associate scope),
     * searchable on the descriptor's title attribute.
     */
    public function associable(Request $request): JsonResponse
    {
        [$relation, $payload] = $this->resolve($request, 'associable');

        /** @var HasMany<Model, Model>|MorphMany<Model, Model> $relation */
        $related = $relation->getRelated();

        $query = $related->newQuery()->whereNull($relation->getQualifiedForeignKeyName());

        return $this->optionsResponse($query, $payload, $related, $request);
    }

    /**
     * Re-parent the chosen records onto this manager's parent: `save()` stamps
     * the foreign key (and morph type) server-side.
     */
    public function associate(Request $request): JsonResponse
    {
        [$relation] = $this->resolve($request, 'associable');

        /** @var HasMany<Model, Model>|MorphMany<Model, Model> $relation */
        $ids = $this->ids($request);
        abort_if($ids === [], 422, 'Nothing to associate.');

        $related = $relation->getRelated();
        $records = $related->newQuery()->whereKey($ids)->get();

        foreach ($records as $record) {
            $relation->save($record);
        }

        return response()->json(['status' => 'success', 'associated' => $records->count()]);
    }

    /**
     * Detach the record(s) from the parent by nulling the foreign key (and the
     * morph type) — the related records themselves are never deleted.
     */
    public function dissociate(Request $request): JsonResponse
    {
        [$relation] = $this->resolve($request, 'associable');

        /** @var HasMany<Model, Model>|MorphMany<Model, Model> $relation */
        $ids = $this->ids($request);
        abort_if($ids === [], 422, 'Nothing to dissociate.');

        // Only records actually owned by THIS parent — the scoped relation
        // query guarantees a foreign id can't dissociate another parent's rows.
        $records = $relation->getQuery()->whereKey($ids)->get();

        foreach ($records as $record) {
            $record->setAttribute($relation->getForeignKeyName(), null);

            if ($relation instanceof MorphMany) {
                $record->setAttribute($relation->getMorphType(), null);
            }

            $record->save();
        }

        return response()->json(['status' => 'success']);
    }

    // -- Shared plumbing --------------------------------------------------------

    /**
     * Search + label the query into the option list both pickers (attach /
     * associate) render: `{ options: [{ id, label }] }`, capped at 50.
     *
     * @param Builder<Model>       $query
     * @param array<string, mixed> $payload
     */
    protected function optionsResponse($query, array $payload, Model $related, Request $request): JsonResponse
    {
        $title  = $this->titleColumn($payload, $related);
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

    /**
     * The record id(s) being acted on: an explicit `ids` array (bulk / picker
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
     * Resolve a related record THROUGH the relationship — an id outside the
     * parent's children 404s, exactly like the table itself.
     */
    protected function findRelated(Relation $relation, mixed $id): Model
    {
        /** @var Model|null $record */
        $record = $relation->getQuery()->whereKey($id)->first();

        abort_if($record === null, 404, (string) __('kinetix.table_record_not_found'));

        return $record;
    }

    /**
     * The manager instance from the descriptor — needed to build its
     * form()/infolist(). Descriptors minted before managers carried the class
     * (or for attach/detach-only tables) 400 here.
     *
     * @param array<string, mixed> $payload
     */
    protected function manager(array $payload, ?Model $parent = null): RelationManager
    {
        $managerClass = $payload['manager'] ?? null;

        abort_unless(
            is_string($managerClass)
                && class_exists($managerClass)
                && is_subclass_of($managerClass, RelationManager::class),
            400,
            'Invalid relation manager.',
        );

        abort_unless(
            $managerClass::getRelationship() === ($payload['relation'] ?? null),
            400,
            'Relation manager mismatch.',
        );

        return $managerClass::make($parent);
    }

    /**
     * Enforce the CHILD model's policy ability when it has one. Without a
     * policy the parent's `update` gate (already enforced in resolve()) rules.
     *
     * @param class-string<Model>       $relatedClass
     * @param Model|class-string<Model> $subject
     */
    protected function authorizeChild(string $relatedClass, string $ability, Model|string $subject): void
    {
        if (Gate::getPolicyFor($relatedClass) === null) {
            return;
        }

        abort_unless(
            Gate::forUser(request()->user())->allows($ability, $subject),
            403,
            (string) __('kinetix.table_write_forbidden'),
        );
    }

    /**
     * Decode the signed descriptor, load the parent, authorize through its
     * policy, and return the required relation type + the payload.
     *
     * @param  'belongsToMany'|'associable'|'many'                   $type
     * @return array{0: Relation, 1: array<string, mixed>, 2: Model}
     */
    protected function resolve(Request $request, string $type = 'many'): array
    {
        try {
            $payload = Crypt::decrypt((string) $request->input('descriptor', $request->input('token', '')));
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

        // Touching children is editing the parent — its policy rules.
        if (Gate::getPolicyFor($parent::class) !== null) {
            abort_unless(
                Gate::forUser($request->user())->allows('update', $parent),
                403,
                'This action is unauthorized.',
            );
        }

        abort_unless(method_exists($parent, $relationName), 400, 'Unknown relation.');
        $relation = $parent->{$relationName}();

        match ($type) {
            'belongsToMany' => abort_unless(
                $relation instanceof BelongsToMany,
                400,
                'Attach/detach requires a BelongsToMany relation.',
            ),
            'associable' => abort_unless(
                $relation instanceof HasMany || $relation instanceof MorphMany,
                400,
                'Associate/dissociate requires a HasMany or MorphMany relation.',
            ),
            default => abort_unless(
                $relation instanceof BelongsToMany || $relation instanceof HasMany || $relation instanceof MorphMany,
                400,
                'Relation record CRUD requires a HasMany, MorphMany, or BelongsToMany relation.',
            ),
        };

        // $payload is proven array by the guards above (a non-array yields a
        // null parent class, which aborts).
        return [$relation, $payload, $parent];
    }
}
