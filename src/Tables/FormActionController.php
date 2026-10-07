<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables;

use Happones\Kinetix\Actions\FormAction;
use Happones\Kinetix\Flash\KinetixFlash;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Support\DescriptorRejection;
use Happones\Kinetix\Support\SignedDescriptor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Runs a table's server-side form actions ({@see FormAction}) — a modal hosting
 * an arbitrary {@see Form} whose submitted values drive
 * a handler — defended exactly like the bulk/record write paths:
 *
 * 1. Binding — the signed descriptor records the user + team it was minted for
 *    ({@see SignedDescriptor}); a leaked token is useless to anyone else.
 * 2. Freshness — descriptors expire (`kinetix.tables.token_ttl`).
 * 3. Allowlist — only an action NAMED in the descriptor's sealed name→class map
 *    may run, and only the class the table registered for it. The client can't
 *    name an arbitrary class.
 * 4. Scoping — when a recordId is sent it is resolved THROUGH the table's own
 *    constraints (resource query / captured where-scope / parent relation), so
 *    a record outside the table the user was looking at is refused. A toolbar
 *    FormAction sends no recordId and runs record-less.
 * 5. Authorization — a resolved record is authorized against the host's policy
 *    (the explicit `writeAbility()`, or `update` when the model has a policy)
 *    before the handler sees it.
 * 6. Validation — the SAME form class is rebuilt server-side and the submitted
 *    values are validated and dehydrated against its rules. The client never
 *    supplies rules and can't skip them; a failed validation redirects back so
 *    the errors surface in the modal's KinetixForm.
 *
 * Only then is the trusted state handed to the action's {@see FormAction::handle()},
 * inside a transaction so a handler that throws leaves nothing half-applied. The
 * response is an Inertia redirect back, so the hosting index reloads with fresh
 * data (mirroring {@see RecordModalController}).
 */
class FormActionController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $descriptor = $this->descriptor($request);

        $name = (string) $request->input('action');

        // Only an action named in the sealed map may run — and only via the
        // class the table registered for it.
        $class = $descriptor['forms'][$name] ?? null;

        abort_unless(
            is_string($class) && is_subclass_of($class, FormAction::class),
            403,
            (string) __('kinetix.form_action_not_allowed'),
        );

        // Resolve the record (if any) through the table's scope: a record action
        // sends its id, a toolbar action sends none. An id outside the scope
        // simply doesn't come back, so it is refused rather than silently acted
        // on.
        $recordId = $request->input('recordId');
        $record   = null;

        if ($recordId !== null && (is_string($recordId) || is_int($recordId))) {
            $record = $this->baseQuery($descriptor)->whereKey($recordId)->first();

            abort_if($record === null, 404, (string) __('kinetix.table_record_not_found'));

            abort_unless(
                $this->authorize($descriptor, $record),
                403,
                (string) __('kinetix.table_write_forbidden'),
            );
        }

        /** @var FormAction $action */
        $action = $class::make($name);

        // Rebuild the SAME form the table serialised, so validation runs against
        // the declared rules — not anything the client sent. validate() throws a
        // ValidationException on failure, which Inertia turns into a redirect
        // back with the error bag the modal's KinetixForm renders.
        $form = $action->getForm($record);

        $submitted = (array) $request->input('data', []);
        $form->validate($submitted);

        $state = $form->getState($submitted);

        DB::transaction(static function () use ($action, $state, $record): void {
            $action->handle($state, $record);
        });

        KinetixFlash::success((string) __('kinetix.form_action_completed'));

        return back()->with('message', (string) __('kinetix.form_action_completed'));
    }

    /**
     * Decrypt and validate the table's signed form-action descriptor. Aborts
     * (not a JSON body) so a bad token short-circuits with the right status,
     * matching the Inertia-redirect shape of this endpoint.
     *
     * @return array{model: class-string<Model>, forms: array<string, class-string<FormAction>>, resource: class-string<resource>|null, scope: array<array-key, mixed>, relation: array<string, mixed>|null, ability: string|null}
     */
    protected function descriptor(Request $request): array
    {
        try {
            $payload = Crypt::decrypt((string) $request->input('descriptor'));
        } catch (Throwable) {
            abort(400, (string) __('kinetix.table_invalid_signature'));
        }

        abort_unless(is_array($payload), 400, (string) __('kinetix.table_invalid_signature'));

        $modelClass = $payload['model'] ?? null;

        abort_unless(
            is_string($modelClass) && class_exists($modelClass) && is_subclass_of($modelClass, Model::class),
            400,
            (string) __('kinetix.table_invalid_model'),
        );

        $rejection = SignedDescriptor::rejection($payload, $request);

        abort_if(
            $rejection !== null,
            403,
            $rejection === DescriptorRejection::Expired
                ? (string) __('kinetix.table_descriptor_expired')
                : (string) __('kinetix.table_write_forbidden'),
        );

        $resource = $payload['resource'] ?? null;

        if (! is_string($resource) || ! class_exists($resource) || ! is_subclass_of($resource, Resource::class)) {
            $resource = null;
        }

        $forms    = $payload['forms']    ?? [];
        $scope    = $payload['scope']    ?? [];
        $ability  = $payload['ability']  ?? null;
        $relation = $payload['relation'] ?? null;

        // Keep only string name → FormAction-subclass pairs.
        $normalizedForms = [];

        if (is_array($forms)) {
            foreach ($forms as $actionName => $actionClass) {
                if (is_string($actionName) && is_string($actionClass) && is_subclass_of($actionClass, FormAction::class)) {
                    $normalizedForms[$actionName] = $actionClass;
                }
            }
        }

        return [
            'model'    => $modelClass,
            'forms'    => $normalizedForms,
            'resource' => $resource,
            'scope'    => is_array($scope) ? $scope : [],
            'relation' => is_array($relation) ? $relation : null,
            'ability'  => is_string($ability) ? $ability : null,
        ];
    }

    /**
     * The table's constrained base query (resource query / captured scope /
     * parent relation) — identical to the bulk/write controllers', so a form
     * action can only ever touch a record the table itself could show.
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
     * The parent-bound query for a relation table's form action.
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
     * Authorize the resolved record through the host's policy — the explicit
     * ability from writeAbility(), or `update` whenever the model has a policy
     * at all.
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
