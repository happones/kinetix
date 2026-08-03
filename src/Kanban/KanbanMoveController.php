<?php

declare(strict_types=1);

namespace Happones\Kinetix\Kanban;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Moves a Kanban card to another board column.
 *
 * The board's signed descriptor carries the model, the status column, the
 * allowed status keys, the ability to enforce and the board's `moveScope()`
 * constraints — so the client never names a class, a status outside the board is
 * rejected, and a record outside the board's scope (e.g. another tenant's) is a
 * 404 rather than a write. The descriptor is bound to the user it was minted for
 * and expires, so a leaked token can't be replayed by someone else.
 *
 * Lives in a controller (not a service-provider closure) so hosts can run
 * `php artisan route:cache`.
 */
class KanbanMoveController
{
    public function __invoke(Request $request): JsonResponse
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

        $modelClass   = $payload['model']        ?? null;
        $statusColumn = $payload['statusColumn'] ?? null;
        $statuses     = $payload['statuses']     ?? [];
        $moveAbility  = $payload['moveAbility']  ?? null;
        $moveScope    = $payload['moveScope']    ?? [];
        $status       = (string) $request->input('status');

        if (! is_string($modelClass) || ! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            return response()->json([
                'status'  => 'error',
                'message' => __('kinetix.table_invalid_model'),
            ], 400);
        }

        // A descriptor is minted for one user; anyone else presenting it is
        // replaying a leaked token.
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

        if (! is_string($statusColumn) || ! is_array($statuses) || ! in_array($status, $statuses, true)) {
            return response()->json([
                'status'  => 'error',
                'message' => __('kinetix.kanban_invalid_status'),
            ], 403);
        }

        // The board's moveScope() constraints bound the lookup — a record
        // outside them (e.g. another tenant's) is a 404.
        $query = $modelClass::query();

        if (is_array($moveScope)) {
            foreach ($moveScope as $column => $value) {
                $query->where((string) $column, $value);
            }
        }

        $recordId = $request->input('recordId');

        // An array id would make find() return a Collection; reject it here
        // rather than letting a type error surface as a 500.
        $record = is_scalar($recordId) ? $query->find($recordId) : null;

        if ($record === null) {
            return response()->json([
                'status'  => 'error',
                'message' => __('kinetix.table_record_not_found'),
            ], 404);
        }

        // Authorize via the host's policy: the explicit ability from
        // authorizeMove(), or `update` whenever a policy exists.
        $ability = is_string($moveAbility)
            ? $moveAbility
            : (Gate::getPolicyFor($modelClass) !== null ? 'update' : null);

        if ($ability !== null && Gate::forUser($request->user())->denies($ability, $record)) {
            return response()->json([
                'status'  => 'error',
                'message' => __('kinetix.table_write_forbidden'),
            ], 403);
        }

        $record->{$statusColumn} = $status;
        $record->save();

        return response()->json(['status' => 'success']);
    }
}
