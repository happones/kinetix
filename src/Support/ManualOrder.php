<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes a drag-reorder onto an integer position column.
 *
 * The rows a drag sends are a window onto a list, never the whole list: one
 * page of a table, a filtered view, a search result, one kanban column of a
 * narrower board. They trade the positions they already hold, so every row
 * outside the window keeps its place. A list whose column holds no usable
 * positions yet (fresh zeros, nulls, repeats) has nothing to trade; it is
 * numbered 1..n once, in the order it is shown: the column, then the key.
 *
 * Shared by table row reorder and kanban card order.
 */
final class ManualOrder
{
    /**
     * The position each row should hold.
     *
     * @param Closure(): Builder<Model> $list     a fresh query over the whole list the rows belong to
     * @param non-empty-list<Model>     $moved    the rows on screen, in their new order
     * @param Closure(Model): bool      $mayWrite the write check for every row the order touches
     * @param int                       $max      the longest list numbered in one step (0 = no cap)
     *
     * @throws ManualOrderRefused
     *
     * @return list<array{Model, int|float}>
     */
    public static function positions(Closure $list, array $moved, string $column, Closure $mayWrite, int $max): array
    {
        return self::traded($list, $moved, $column)
            ?? self::renumbered($list, $moved, $column, $mayWrite, $max);
    }

    /**
     * Save through the model (not a query-builder update) so the host's
     * observers and audit-log listeners still fire. A row that keeps its
     * position isn't saved, so it fires no events. Run it inside the caller's
     * transaction.
     *
     * @param list<array{Model, int|float}> $positions
     */
    public static function save(array $positions, string $column): void
    {
        foreach ($positions as [$record, $position]) {
            if (self::samePosition($record->getAttribute($column), $position)) {
                continue;
            }

            $record->{$column} = $position;
            $record->save();
        }
    }

    /**
     * The positions the moved rows already hold, handed out again in the new
     * order. Null when there is nothing to trade: a row without a position,
     * two rows sharing one, or a row outside the window sharing one.
     *
     * @param  Closure(): Builder<Model>          $list
     * @param  non-empty-list<Model>              $moved
     * @return list<array{Model, int|float}>|null
     */
    private static function traded(Closure $list, array $moved, string $column): ?array
    {
        $slots = [];

        foreach ($moved as $record) {
            $value = $record->getAttribute($column);

            if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
                return null;
            }

            $slots[(string) ($value + 0)] = $value + 0;
        }

        if (count($slots) !== count($moved)) {
            return null;
        }

        $sharedOutside = $list()
            ->whereKeyNot(array_map(static fn (Model $record): mixed => $record->getKey(), $moved))
            ->whereIn($moved[0]->qualifyColumn($column), array_values($slots))
            ->exists();

        if ($sharedOutside) {
            return null;
        }

        $values = array_values($slots);
        sort($values, SORT_NUMERIC);

        return array_map(
            static fn (Model $record, int|float $position): array => [$record, $position],
            $moved,
            $values,
        );
    }

    /**
     * Number the whole list 1..n in the order it is shown, with the moved rows
     * placed into the places they held. Bounded by $max, and every row it
     * renumbers must pass the same write check as the moved ones.
     *
     * @param Closure(): Builder<Model> $list
     * @param non-empty-list<Model>     $moved
     * @param Closure(Model): bool      $mayWrite
     *
     * @throws ManualOrderRefused
     *
     * @return list<array{Model, int}>
     */
    private static function renumbered(Closure $list, array $moved, string $column, Closure $mayWrite, int $max): array
    {
        $model    = $moved[0];
        $position = $model->qualifyColumn($column);

        $rows = $list()
            ->reorder()
            ->orderBy($position)
            ->orderBy($model->getQualifiedKeyName())
            ->select([$model->getQualifiedKeyName().' as kinetix_key', $position.' as kinetix_position'])
            ->when($max > 0, static fn (Builder $query): Builder => $query->limit($max + 1))
            ->toBase()
            ->get();

        if ($max > 0 && $rows->count() > $max) {
            throw ManualOrderRefused::unnumbered();
        }

        $order   = $rows->map(static fn (object $row): string => (string) $row->kinetix_key)->all();
        $current = $rows->mapWithKeys(static fn (object $row): array => [(string) $row->kinetix_key => $row->kinetix_position])->all();

        $movedByKey = [];

        foreach ($moved as $record) {
            $movedByKey[(string) $record->getKey()] = $record;
        }

        // The places the moved rows held, in list order, take them in the
        // requested order.
        $places = array_keys(array_intersect($order, array_keys($movedByKey)));

        foreach ($places as $i => $place) {
            $order[$place] = (string) $moved[$i]->getKey();
        }

        $positions = [];
        $others    = [];

        foreach (array_values($order) as $index => $key) {
            if (self::samePosition($current[$key] ?? null, $index + 1)) {
                continue;
            }

            if (isset($movedByKey[$key])) {
                $positions[] = [$movedByKey[$key], $index + 1];
            } else {
                $others[$key] = $index + 1;
            }
        }

        if ($others !== []) {
            foreach ($list()->whereKey(array_keys($others))->get() as $record) {
                if (! $mayWrite($record)) {
                    throw ManualOrderRefused::forbidden();
                }

                $positions[] = [$record, $others[(string) $record->getKey()]];
            }
        }

        return $positions;
    }

    private static function samePosition(mixed $current, int|float $position): bool
    {
        return is_numeric($current) && (float) $current === (float) $position;
    }
}
