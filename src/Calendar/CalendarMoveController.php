<?php

declare(strict_types=1);

namespace Happones\Kinetix\Calendar;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Reschedules a calendar event to a new start instant (drag-and-drop).
 *
 * The calendar's signed descriptor carries the model, the date columns to
 * rewrite, the ability to enforce and the calendar's `moveScope()`
 * constraints — so the client never names a class or a column, and a record
 * outside the calendar's scope (e.g. another tenant's) is a 404 rather than a
 * write. The descriptor is bound to the user it was minted for and expires,
 * so a leaked token can't be replayed by someone else.
 *
 * The end column (when configured) shifts by the same delta as the start, so
 * an event's duration survives the move.
 *
 * Lives in a controller (not a service-provider closure) so hosts can run
 * `php artisan route:cache`.
 */
class CalendarMoveController
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

        $modelClass  = $payload['model']       ?? null;
        $dateColumn  = $payload['dateColumn']  ?? null;
        $endColumn   = $payload['endColumn']   ?? null;
        $moveAbility = $payload['moveAbility'] ?? null;
        $moveScope   = $payload['moveScope']   ?? [];

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

        try {
            $newStart = Carbon::parse((string) $request->input('start'));
        } catch (Throwable) {
            $newStart = null;
        }

        if (! is_string($dateColumn) || $newStart === null) {
            return response()->json([
                'status'  => 'error',
                'message' => __('kinetix.calendar_invalid_date'),
            ], 422);
        }

        // The calendar's moveScope() constraints bound the lookup — a record
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

        $oldStart = $record->getAttribute($dateColumn);

        if ($oldStart === null) {
            return response()->json([
                'status'  => 'error',
                'message' => __('kinetix.calendar_invalid_date'),
            ], 422);
        }

        // Dates persist in the app timezone; the end shifts by the same
        // delta as the start so the event's duration is preserved.
        $newStart     = $newStart->setTimezone(config('app.timezone'));
        $deltaSeconds = Carbon::parse($oldStart)->diffInSeconds($newStart, false);

        $record->{$dateColumn} = $newStart;

        if (is_string($endColumn)) {
            $oldEnd = $record->getAttribute($endColumn);

            if ($oldEnd !== null) {
                $record->{$endColumn} = Carbon::parse($oldEnd)->addSeconds($deltaSeconds);
            }
        }

        $record->save();

        return response()->json(['status' => 'success']);
    }
}
