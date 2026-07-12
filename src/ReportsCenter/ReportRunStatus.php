<?php

declare(strict_types=1);

namespace Happones\Kinetix\ReportsCenter;

use Happones\Kinetix\Support\Contracts\HasColor;
use Happones\Kinetix\Support\Contracts\HasLabel;

/**
 * Lifecycle state of a `ReportRun`.
 *
 * - `Pending`   — queued, not yet picked up by a worker.
 * - `Running`   — a worker is actively chunking the query and writing rows.
 * - `Completed` — the file was written and stored; downloadable until `expires_at`.
 * - `Failed`    — every retry attempt threw; see `error_message`.
 * - `Cancelled` — the visitor cancelled it before or during processing.
 */
enum ReportRunStatus: string implements HasColor, HasLabel
{
    case Pending   = 'pending';
    case Running   = 'running';
    case Completed = 'completed';
    case Failed    = 'failed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return trans('kinetix.report_run_status_'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending, self::Cancelled => 'gray',
            self::Running                  => 'info',
            self::Completed                => 'success',
            self::Failed                   => 'danger',
        };
    }

    /** Whether a run in this status can still be cancelled. */
    public function isCancellable(): bool
    {
        return $this === self::Pending || $this === self::Running;
    }

    /** Whether a run in this status can be retried (a fresh run is dispatched). */
    public function isRetryable(): bool
    {
        return $this === self::Failed || $this === self::Cancelled;
    }
}
