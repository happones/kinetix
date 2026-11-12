<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A table query ran past its time budget and the database cancelled it.
 *
 * Dispatched once per cancelled phase, so a listener can log or alert on slow
 * lists. It carries no search term, filter value or user input: only what is
 * needed to find the slow table.
 *
 * `phase` is `count` (the total behind the pager), `rows` (the page itself) or
 * `aggregates` (KPI stats and column summaries).
 */
final class TableQueryTimedOut
{
    use Dispatchable;

    public function __construct(
        /** The Eloquent model class the table lists. */
        public readonly string $model,
        /** 'count', 'rows' or 'aggregates'. */
        public readonly string $phase,
        /** The per-statement budget that was exceeded, in milliseconds. */
        public readonly int $timeoutMs,
        /** The active team's key, or null with teams off or none resolved. */
        public readonly int|string|null $teamKey = null,
    ) {}
}
