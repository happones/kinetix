<?php

declare(strict_types=1);

namespace Happones\Kinetix\Kanban;

use Happones\Kinetix\Support\DescriptorRejection;
use Happones\Kinetix\Support\ManualOrder;
use Happones\Kinetix\Support\ManualOrderRefused;
use Happones\Kinetix\Support\SignedDescriptor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Moves a Kanban card to another board column.
 *
 * The board's signed descriptor carries the model, the status column, the
 * allowed status keys, the ability to enforce and the board's `moveScope()`
 * constraints — so the client never names a class, a status outside the board is
 * rejected, and a record outside the board's scope (e.g. another tenant's) is a
 * 404 rather than a write. The descriptor is bound to the user and team it was
 * minted for and expires, so a leaked token can't be replayed by someone else.
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

        // The descriptor is bound to the user and team it was minted for, and
        // expires ({@see SignedDescriptor}). Anyone else presenting it is
        // replaying a leaked token.
        $rejection = SignedDescriptor::rejection($payload, $request);

        if ($rejection !== null) {
            return response()->json([
                'status'  => 'error',
                'message' => $rejection === DescriptorRejection::Expired
                    ? __('kinetix.table_descriptor_expired')
                    : __('kinetix.table_write_forbidden'),
            ], 403);
        }

        if (! is_string($statusColumn) || ! is_array($statuses) || ! in_array($status, $statuses, true)) {
            return response()->json([
                'status'  => 'error',
                'message' => __('kinetix.kanban_invalid_status'),
            ], 403);
        }

        // The board's moveScope() constraints bound every lookup — a record
        // outside them (e.g. another tenant's) is a 404. A fresh builder per
        // use: find() and where() mutate the one they run on.
        $scope = static function () use ($modelClass, $moveScope): Builder {
            $query = $modelClass::query();

            if (is_array($moveScope)) {
                foreach ($moveScope as $column => $value) {
                    $query->where((string) $column, $value);
                }
            }

            return $query;
        };

        $recordId = $request->input('recordId');

        // An array id would make find() return a Collection; reject it here
        // rather than letting a type error surface as a 500.
        $record = is_scalar($recordId) ? $scope()->find($recordId) : null;

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

        $mayWrite = static fn (Model $card): bool => $ability === null
            || Gate::forUser($request->user())->allows($ability, $card);

        if (! $mayWrite($record)) {
            return response()->json([
                'status'  => 'error',
                'message' => __('kinetix.table_write_forbidden'),
            ], 403);
        }

        $orderColumn = $payload['orderColumn'] ?? null;
        $order       = $request->input('order');

        if (! is_string($orderColumn) || $orderColumn === '' || ! is_array($order)) {
            $record->{$statusColumn} = $status;
            $record->save();

            return response()->json(['status' => 'success']);
        }

        // A reorderable board sends the destination column's cards in their
        // new order. Reject nested arrays outright, and let a repeated id
        // claim one position; a dragged card missing from the list lands at
        // its end.
        $ids = collect($order)
            ->push($record->getKey())
            ->filter(static fn (mixed $id): bool => is_scalar($id))
            ->unique(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        $max = (int) config('kinetix.tables.reorder_max', 1000);

        if ($max > 0 && count($ids) > $max) {
            return response()->json([
                'status'  => 'error',
                'message' => __('kinetix.table_reorder_too_large'),
            ], 422);
        }

        // A card dropped in from another column brings that column's
        // position, which means nothing here: it takes a fresh one.
        $arrivals = (string) $record->getAttribute($statusColumn) === $status
            ? []
            : [(string) $record->getKey()];

        // The status and the order land together or not at all. The cards are
        // read inside the transaction, locked, so a concurrent reorder can't
        // slip in between reading their positions and writing them. Only the
        // cards whose position changes are written and write-checked.
        try {
            DB::transaction(function () use ($record, $statusColumn, $status, $scope, $ids, $orderColumn, $mayWrite, $max, $arrivals): void {
                $record->{$statusColumn} = $status;
                $record->save();

                $column = static fn (): Builder => $scope()->where($record->qualifyColumn($statusColumn), $status);
                $cards  = $column()->whereKey($ids)->lockForUpdate()->get()
                    ->keyBy(static fn (Model $card): string => (string) $card->getKey());

                $moved = array_values(array_filter(array_map(
                    static fn (mixed $id): ?Model => $cards->get((string) $id),
                    $ids,
                )));

                if ($moved !== []) {
                    ManualOrder::save(ManualOrder::positions($column, $moved, $orderColumn, $mayWrite, $max, $arrivals), $orderColumn);
                }
            });
        } catch (ManualOrderRefused $refused) {
            return response()->json([
                'status'  => 'error',
                'message' => $refused->getMessage(),
            ], $refused->getCode());
        }

        return response()->json(['status' => 'success']);
    }
}
