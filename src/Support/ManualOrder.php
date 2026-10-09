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
 * outside the window keeps its position. A row arriving from another list (a
 * card dropped into a new column) brings no position of this list: it takes a
 * fresh one after the list's last. A window whose rows hold no usable
 * positions yet (fresh zeros, nulls, repeats) has nothing to trade; the list
 * is numbered 1..n once, in the order it is shown: the column, then the key.
 *
 * Only rows whose position actually changes are written, and every one of
 * them must pass the caller's write check.
 *
 * Shared by table row reorder and kanban card order.
 */
final class ManualOrder
{
    /**
     * The rows whose position changes, with the position each should hold.
     *
     * @param Closure(): Builder<Model> $list     a fresh query over the whole list the rows belong to
     * @param non-empty-list<Model>     $moved    the rows on screen, in their new order
     * @param Closure(Model): bool      $mayWrite the write check for every row whose position changes
     * @param int                       $max      the longest list numbered in one step (0 = no cap)
     * @param list<string>              $arrivals keys of moved rows whose position belongs to another list
     *
     * @throws ManualOrderRefused
     *
     * @return list<array{Model, int|float}>
     */
    public static function positions(Closure $list, array $moved, string $column, Closure $mayWrite, int $max, array $arrivals = []): array
    {
        $positions = self::traded($list, $moved, $column, $arrivals)
            ?? self::renumbered($list, $moved, $column, $max);

        $changed = array_values(array_filter(
            $positions,
            static fn (array $entry): bool => ! self::samePosition($entry[0]->getAttribute($column), $entry[1]),
        ));

        foreach ($changed as [$record]) {
            if (! $mayWrite($record)) {
                throw ManualOrderRefused::forbidden();
            }
        }

        return $changed;
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
     * order, plus a fresh one after the list's last for each arrival. Null
     * when there is nothing to trade: a row without a position, or two rows
     * sharing one.
     *
     * A row outside the window may share a position with one inside it (a
     * list numbered per group, a scope wider than the rows shown): it keeps
     * its own, and the tie is broken by key as everywhere else. Numbering the
     * whole list for it would write rows the user never saw.
     *
     * @param  Closure(): Builder<Model>          $list
     * @param  non-empty-list<Model>              $moved
     * @param  list<string>                       $arrivals
     * @return list<array{Model, int|float}>|null
     */
    private static function traded(Closure $list, array $moved, string $column, array $arrivals): ?array
    {
        $slots    = [];
        $arriving = [];

        foreach ($moved as $record) {
            if (in_array((string) $record->getKey(), $arrivals, true)) {
                $arriving[] = $record->getKey();

                continue;
            }

            $value = $record->getAttribute($column);

            if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
                return null;
            }

            $slots[(string) ($value + 0)] = $value + 0;
        }

        if (count($slots) + count($arriving) !== count($moved)) {
            return null;
        }

        if ($arriving !== []) {
            array_push($slots, ...self::freshSlots(
                static fn (): Builder => $list()->whereKeyNot($arriving),
                $moved,
                $column,
                $arrivals,
            ));
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
     * Positions for the rows arriving from another list. A single arrival
     * takes a free position between its new neighbours when there is one, so
     * none of them moves (a drop between 10 and 30 lands on 20); otherwise
     * the arrivals take the positions after the list's last.
     *
     * @param  Closure(): Builder<Model> $others   the list without the arrivals
     * @param  non-empty-list<Model>     $moved
     * @param  list<string>              $arrivals
     * @return list<int>
     */
    private static function freshSlots(Closure $others, array $moved, string $column, array $arrivals): array
    {
        $qualified = $moved[0]->qualifyColumn($column);
        $keys      = array_map(static fn (Model $record): string => (string) $record->getKey(), $moved);
        $arriving  = array_values(array_intersect($keys, $arrivals));

        if (count($arriving) === 1) {
            $at   = (int) array_search($arriving[0], $keys, true);
            $prev = $at > 0 ? $moved[$at - 1]->getAttribute($column) : null;
            $next = $at < count($moved) - 1 ? $moved[$at + 1]->getAttribute($column) : null;

            $between = match (true) {
                is_numeric($prev) && is_numeric($next) => intdiv((int) floor((float) $prev) + (int) ceil((float) $next), 2),
                $prev === null    && is_numeric($next) => (int) ceil((float) $next) - 1,
                default                                => null,
            };

            if (
                $between !== null
                && $between >= 1
                && (! is_numeric($prev) || $between > (float) $prev)
                && $between < (float) $next
                && ! $others()->where($qualified, $between)->exists()
            ) {
                return [$between];
            }
        }

        $last = $others()->max($qualified);
        $top  = is_numeric($last) ? (int) floor((float) $last) : 0;

        return range($top + 1, $top + count($arriving));
    }

    /**
     * Number the whole list 1..n in the order it is shown, with the moved rows
     * placed into the places they held. Bounded by $max.
     *
     * @param Closure(): Builder<Model> $list
     * @param non-empty-list<Model>     $moved
     *
     * @throws ManualOrderRefused
     *
     * @return list<array{Model, int}>
     */
    private static function renumbered(Closure $list, array $moved, string $column, int $max): array
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
            foreach ($list()->whereKey(array_keys($others))->lockForUpdate()->get() as $record) {
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
