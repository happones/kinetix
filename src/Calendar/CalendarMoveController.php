<?php

declare(strict_types=1);

namespace Happones\Kinetix\Calendar;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Happones\Kinetix\Support\DescriptorRejection;
use Happones\Kinetix\Support\KinetixTimezone;
use Happones\Kinetix\Support\SignedDescriptor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Reschedules a calendar event to a new start instant (drag-and-drop), or
 * resizes it to a new end instant (dragging its end edge).
 *
 * The calendar's signed descriptor carries the model, the date columns to
 * rewrite, which of the two writes the calendar allows, the ability to
 * enforce and the calendar's `moveScope()` constraints — so the client never
 * names a class or a column, and a record outside the calendar's scope (e.g.
 * another tenant's) is a 404 rather than a write. The descriptor is bound to
 * the user and team it was minted for and expires, so a leaked token can't be
 * replayed by someone else.
 *
 * On a move the end column (when configured) shifts by the same delta as the
 * start, so an event's duration survives it. A resize only rewrites the end.
 *
 * Lives in a controller (not a service-provider closure) so hosts can run
 * `php artisan route:cache`.
 */
class CalendarMoveController
{
    public function __invoke(Request $request): JsonResponse
    {
        $resolved = $this->resolveRecord($request, 'moveable');

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [$record, $dateColumn, $endColumn, $timezone] = $resolved;

        $newStart = $this->instant($request, 'start');
        $oldStart = $record->getAttribute($dateColumn);

        if ($newStart === null || $oldStart === null) {
            return $this->invalidDate();
        }

        // The browser moves the start on the calendar's wall clock (whole
        // days in the month view), so the end moves by the same wall-clock
        // delta: an all-day event moved across a DST change still ends at a
        // midnight, where elapsed seconds left it an hour off (a timed event).
        $delta = self::wallClock(Carbon::parse($oldStart), $timezone)
            ->diffInSeconds(self::wallClock($newStart, $timezone), false);

        $record->{$dateColumn} = $newStart;

        if ($endColumn !== null) {
            $oldEnd = $record->getAttribute($endColumn);

            if ($oldEnd !== null) {
                $shifted = self::wallClock(Carbon::parse($oldEnd), $timezone)->addSeconds((int) $delta);

                $record->{$endColumn} = Carbon::parse($shifted->format('Y-m-d H:i:s.u'), $timezone)
                    ->setTimezone(config('app.timezone'));
            }
        }

        $record->save();

        return response()->json(['status' => 'success']);
    }

    /**
     * Give the event a new end. It may not end before it starts; the start
     * stays where it is.
     */
    public function resize(Request $request): JsonResponse
    {
        $resolved = $this->resolveRecord($request, 'resizable');

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [$record, $dateColumn, $endColumn] = $resolved;

        $newEnd = $this->instant($request, 'end');
        $start  = $record->getAttribute($dateColumn);

        if ($endColumn === null || $newEnd === null || $start === null || $newEnd->lt(Carbon::parse($start))) {
            return $this->invalidDate();
        }

        $record->{$endColumn} = $newEnd;
        $record->save();

        return response()->json(['status' => 'success']);
    }

    /**
     * Everything both writes check before touching the record: the
     * descriptor's signature, binding and expiry, that it allows this write,
     * the record lookup inside `moveScope()`, and the host's policy.
     *
     * @param  'moveable'|'resizable'                                 $write the descriptor flag allowing the write
     * @return array{Model, string, string|null, string}|JsonResponse the record, its date column, end column and the calendar's timezone
     */
    private function resolveRecord(Request $request, string $write): array|JsonResponse
    {
        try {
            $payload = Crypt::decrypt((string) $request->input('model'));
        } catch (Throwable) {
            return $this->error('kinetix.table_invalid_signature', 400);
        }

        if (! is_array($payload)) {
            return $this->error('kinetix.table_invalid_signature', 400);
        }

        $modelClass  = $payload['model']       ?? null;
        $dateColumn  = $payload['dateColumn']  ?? null;
        $endColumn   = $payload['endColumn']   ?? null;
        $moveAbility = $payload['moveAbility'] ?? null;
        $moveScope   = $payload['moveScope']   ?? [];

        if (! is_string($modelClass) || ! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            return $this->error('kinetix.table_invalid_model', 400);
        }

        // The descriptor is bound to the user and team it was minted for, and
        // expires ({@see SignedDescriptor}). Anyone else presenting it is
        // replaying a leaked token.
        $rejection = SignedDescriptor::rejection($payload, $request);

        if ($rejection !== null) {
            return $this->error($rejection === DescriptorRejection::Expired
                ? 'kinetix.table_descriptor_expired'
                : 'kinetix.table_write_forbidden', 403);
        }

        // A resizable-only calendar's descriptor can't move events, nor a
        // moveable-only one resize them. Descriptors minted before resizing
        // existed carry neither flag and were only ever minted for moves.
        if (($payload[$write] ?? $write === 'moveable') !== true) {
            return $this->error('kinetix.table_write_forbidden', 403);
        }

        if (! is_string($dateColumn)) {
            return $this->invalidDate();
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

        if (! $record instanceof Model) {
            return $this->error('kinetix.table_record_not_found', 404);
        }

        // Authorize via the host's policy: the explicit ability from
        // authorizeMove(), or `update` whenever a policy exists.
        $ability = is_string($moveAbility)
            ? $moveAbility
            : (Gate::getPolicyFor($modelClass) !== null ? 'update' : null);

        if ($ability !== null && Gate::forUser($request->user())->denies($ability, $record)) {
            return $this->error('kinetix.table_write_forbidden', 403);
        }

        // Descriptors minted before the calendar's timezone was sealed fall
        // back to the app's.
        $timezone = $payload['timezone'] ?? null;

        return [
            $record,
            $dateColumn,
            is_string($endColumn) ? $endColumn : null,
            is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : KinetixTimezone::default(),
        ];
    }

    /**
     * The request's instant under `$key`, in the app timezone the dates
     * persist in; null when it's missing or doesn't parse (an empty string
     * would otherwise parse as "now").
     */
    private function instant(Request $request, string $key): ?Carbon
    {
        $value = $request->input($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The instant's wall-clock reading in `$timezone`, as a UTC time with no
     * DST: arithmetic on it is calendar arithmetic.
     */
    private static function wallClock(CarbonInterface $instant, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $instant->copy()->setTimezone($timezone)->format('Y-m-d H:i:s.u'),
            'UTC',
        );
    }

    private function invalidDate(): JsonResponse
    {
        return $this->error('kinetix.calendar_invalid_date', 422);
    }

    private function error(string $key, int $status): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => __($key)], $status);
    }
}
