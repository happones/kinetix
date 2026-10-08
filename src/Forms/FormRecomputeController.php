<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms;

use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Support\DescriptorRejection;
use Happones\Kinetix\Support\SignedDescriptor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The server-driven form reactivity endpoint ($get/$set). Given a form's signed
 * descriptor and the values entered so far, it REBUILDS the form server-side
 * and returns its freshly recomputed schema plus any values its
 * `afterStateUpdated()` closures pushed back.
 *
 * The client never names a class or sends a closure: the descriptor (sealed by
 * {@see Form::buildRecomputeDescriptor()}) carries only class references + the
 * record id, bound to the user/team/expiry like every Kinetix descriptor. The
 * form — and therefore every reactive closure — comes from its own class, so
 * this endpoint runs only code the application defined. A record-bound form is
 * authorized against the host's policy before anything is read.
 */
class FormRecomputeController
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = SignedDescriptor::open((string) $request->input('descriptor'));

        if ($payload === null) {
            return response()->json(['message' => __('kinetix.form_session_expired')], 400);
        }

        $rejection = SignedDescriptor::rejection($payload, $request);

        if ($rejection !== null) {
            return response()->json([
                'message' => $rejection === DescriptorRejection::Expired
                    ? __('kinetix.form_session_expired')
                    : __('kinetix.table_write_forbidden'),
            ], 403);
        }

        $form = $this->rebuild($payload);

        if ($form instanceof JsonResponse) {
            return $form;
        }

        // Which live fields the user changed since the last recompute: only
        // their afterStateUpdated hooks run. Absent (an older client), all do.
        $changed = $request->input('changed');
        $changed = match (true) {
            is_array($changed)  => array_values(array_filter($changed, 'is_string')),
            is_string($changed) => [$changed],
            default             => null,
        };

        $result = $form->recompute((array) $request->input('data', []), $changed);

        return response()->json($result);
    }

    /**
     * Rebuild the form from the descriptor — a `Form` subclass instantiated for
     * the record, or a resource's `form()`. Returns the error response when the
     * descriptor is malformed or the record/policy check fails.
     *
     * @param array<string, mixed> $payload
     */
    protected function rebuild(array $payload): Form|JsonResponse
    {
        $modelClass = $payload['model'] ?? null;
        $record     = null;

        // Resolve the record (edit forms) through the resource query when there
        // is one, so tenancy/scope still bounds it; authorize it like the
        // record modals do.
        $recordId = $payload['recordId'] ?? null;
        $resource = $payload['resource'] ?? null;
        $resource = is_string($resource) && class_exists($resource) && is_subclass_of($resource, Resource::class)
            ? $resource
            : null;

        if ($recordId !== null) {
            if (! is_string($modelClass) || ! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
                return response()->json(['message' => __('kinetix.table_invalid_model')], 400);
            }

            $query  = $resource !== null ? $resource::getEloquentQuery() : $modelClass::query();
            $record = $query->whereKey($recordId)->first();

            if ($record === null) {
                return response()->json(['message' => __('kinetix.table_record_not_found')], 404);
            }

            if (! $this->authorize($modelClass, $record)) {
                return response()->json(['message' => __('kinetix.table_write_forbidden')], 403);
            }
        }

        $operation = is_string($payload['operation'] ?? null) ? $payload['operation'] : 'create';

        // A create form was built around a fresh model instance (that is how
        // Kinetix builds every create form). Rebuilding it around null lost
        // the model, and with it every relationship Select's options.
        if ($record === null && is_string($modelClass) && is_subclass_of($modelClass, Model::class)) {
            $record = new $modelClass;
        }

        // A Form subclass rebuilds itself; a resource form comes from form().
        $formClass = $payload['formClass'] ?? null;

        if (is_string($formClass) && class_exists($formClass) && is_subclass_of($formClass, Form::class)) {
            return $formClass::make($record)->operation($operation);
        }

        if ($resource !== null) {
            return $resource::form(Form::make($record)->operation($operation))->reactiveVia($resource);
        }

        return response()->json(['message' => __('kinetix.form_session_expired')], 400);
    }

    /**
     * Enforce the record's policy (view for a loaded record) when the model has
     * one — mirrors RecordModalController; without a policy the host owns access.
     *
     * @param class-string<Model> $modelClass
     */
    protected function authorize(string $modelClass, Model $record): bool
    {
        if (Gate::getPolicyFor($modelClass) === null) {
            return true;
        }

        $ability = $record->exists ? 'update' : 'view';

        return Gate::forUser(request()->user())->allows($ability, $record);
    }
}
