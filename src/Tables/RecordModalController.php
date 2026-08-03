<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables;

use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Infolists\Infolist;
use Happones\Kinetix\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;

/**
 * Powers the in-table modal CRUD of a "simple" resource (Table::recordModals()).
 *
 * Every request carries the table's signed descriptor — an encrypted
 * { model, resource } pair — so the client never names the model or resource
 * class; the endpoint trusts only what it decrypts. `resolve()` returns a fresh
 * form/infolist for the record (an XHR, JSON); store/update/destroy persist
 * through the resource's own `form()` and return an Inertia redirect back to the
 * index so the table reloads with fresh data and validation errors surface in
 * the modal's KinetixForm.
 *
 * Authorization mirrors the record actions and kanban-move: when the model has a
 * policy, the matching ability (view/create/update/delete) is enforced per
 * request; without a policy nothing is enforced here (the host owns access).
 */
class RecordModalController
{
    /**
     * Fetch a fresh form (create/edit) or infolist (view) for the record.
     */
    public function resolve(Request $request): JsonResponse
    {
        [$modelClass, $resource] = $this->descriptor($request);

        $mode = (string) $request->input('mode', 'edit');

        if ($mode === 'create') {
            $form = $resource::form(Form::make(new $modelClass)->operation('create'))->fill();

            return response()->json(['form' => $form->toArray()]);
        }

        $record = $this->findRecord($resource, $modelClass, $request->input('id'));

        if ($mode === 'view') {
            $this->authorize($modelClass, 'view', $record);

            return response()->json([
                'infolist' => $resource::infolist(Infolist::make($record))->toArray(),
            ]);
        }

        // Default: edit — a fresh, filled form (so concurrent edits aren't lost).
        $this->authorize($modelClass, 'update', $record);

        $form = $resource::form(Form::make($record)->operation('edit'))->fill($record);

        return response()->json(['form' => $form->toArray()]);
    }

    /**
     * Create a record from the submitted form values.
     */
    public function store(Request $request): RedirectResponse
    {
        [$modelClass, $resource] = $this->descriptor($request);

        $this->authorize($modelClass, 'create', $modelClass);

        $form = $resource::form(Form::make(new $modelClass)->operation('create'));
        $form->validate((array) $request->input('data', []));

        $data = $resource::mutateFormDataBeforeSave(
            $form->getState((array) $request->input('data', [])),
            'create',
        );

        $resource::getEloquentQuery()->create($data);

        return back()->with('message', (string) __('kinetix.record_created'));
    }

    /**
     * Update an existing record from the submitted form values.
     */
    public function update(Request $request): RedirectResponse
    {
        [$modelClass, $resource] = $this->descriptor($request);

        $record = $this->findRecord($resource, $modelClass, $request->input('id'));
        $this->authorize($modelClass, 'update', $record);

        $form = $resource::form(Form::make($record)->operation('edit'));
        $form->validate((array) $request->input('data', []));

        $data = $resource::mutateFormDataBeforeSave(
            $form->getState((array) $request->input('data', [])),
            'edit',
            $record,
        );

        $record->update($data);

        return back()->with('message', (string) __('kinetix.record_updated'));
    }

    /**
     * Delete a record.
     */
    public function destroy(Request $request): RedirectResponse
    {
        [$modelClass, $resource] = $this->descriptor($request);

        $record = $this->findRecord($resource, $modelClass, $request->input('id'));
        $this->authorize($modelClass, 'delete', $record);

        $record->delete();

        return back()->with('message', (string) __('kinetix.record_deleted'));
    }

    /**
     * Decode the signed descriptor into the trusted [model, resource] pair.
     *
     * @return array{0: class-string<Model>, 1: class-string<\Happones\Kinetix\Resources\Resource>}
     */
    protected function descriptor(Request $request): array
    {
        try {
            $payload = Crypt::decrypt((string) $request->input('token'));
        } catch (\Throwable) {
            abort(400, 'Invalid record descriptor.');
        }

        $modelClass = is_array($payload) ? ($payload['model'] ?? null) : null;
        $resource   = is_array($payload) ? ($payload['resource'] ?? null) : null;

        abort_unless(
            is_string($modelClass) && class_exists($modelClass) && is_subclass_of($modelClass, Model::class),
            400,
            'Invalid model class.',
        );
        abort_unless(
            is_string($resource) && class_exists($resource) && is_subclass_of($resource, Resource::class),
            400,
            'Invalid resource class.',
        );

        return [$modelClass, $resource];
    }

    /**
     * Look up a record through the resource's (tenant-scoped) base query.
     *
     * @param class-string<\Happones\Kinetix\Resources\Resource> $resource
     * @param class-string<Model>                                $modelClass
     */
    protected function findRecord(string $resource, string $modelClass, mixed $id): Model
    {
        $record = $resource::getEloquentQuery()->whereKey($id)->first();

        abort_if($record === null, 404, (string) __('kinetix.table_record_not_found'));

        return $record;
    }

    /**
     * Enforce the operation's policy ability when the model has a policy. Without
     * one, access is the host's responsibility (mirrors cell-update/kanban-move).
     *
     * @param class-string<Model>       $modelClass
     * @param Model|class-string<Model> $subject
     */
    protected function authorize(string $modelClass, string $ability, Model|string $subject): void
    {
        if (Gate::getPolicyFor($modelClass) === null) {
            return;
        }

        abort_unless(
            Gate::forUser(request()->user())->allows($ability, $subject),
            403,
            (string) __('kinetix.table_write_forbidden'),
        );
    }
}
