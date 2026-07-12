<?php

declare(strict_types=1);

namespace Happones\Kinetix\ReportsCenter;

use Carbon\CarbonInterface;
use Happones\Kinetix\Exports\Exporter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * A large-dataset, queued, DB-tracked report type. Extends `Exporter`
 * unchanged for data access (`query()`/`getColumns()`/`chunkSize()`/
 * `format()`) — "obtaining data in chunks" reuses the exact same Eloquent
 * chunk machinery rather than a parallel abstraction. What `Report` adds on
 * top is tracking: every run is a `ReportRun` row with progress, the ability
 * to cancel/retry, and optional scheduling via a `ReportSchedule`.
 */
abstract class Report extends Exporter
{
    /**
     * Display label in the launcher list / schedule editor.
     */
    public function label(): string
    {
        return (string) str(class_basename(static::class))->headline();
    }

    /**
     * Supporting description shown in the launcher list. Null hides it.
     */
    public function description(): ?string
    {
        return null;
    }

    /**
     * Whether `ReportRunJob` may run a single upfront `COUNT(*)` to populate
     * `total_rows`/`percent`. Override to return false when even counting the
     * query is too expensive — progress then stays indeterminate (no
     * percentage), but `processed_rows` still increments as rows are written.
     */
    public function estimatesTotal(): bool
    {
        return true;
    }

    /**
     * The tracked entry point: creates a `ReportRun` row and dispatches
     * `ReportRunJob` against it. `$at` delays a single run (not recurrence —
     * recurring cadence is a separate `ReportSchedule` concept, driven by
     * `kinetix:report-schedules:dispatch-due`, not this method).
     *
     * @param array<string, mixed> $parameters
     */
    public function schedule(
        ?CarbonInterface $at = null,
        ?Model $launchedBy = null,
        array $parameters = [],
        ?int $reportScheduleId = null,
    ): ReportRun {
        return app(ReportRunDispatcher::class)->dispatch(
            $this,
            $at,
            $launchedBy,
            $parameters,
            $reportScheduleId,
        );
    }

    /**
     * Overridden so a `Report` is never accidentally routed through the
     * inherited, untracked `Exporter::export()`/`ExportProcessor` pipeline
     * (no `ReportRun` row, no progress, no cancellation) — always redirects
     * into the tracked {@see schedule()} instead.
     *
     * @param array<string, mixed> $parameters
     */
    public function export(?Model $recipient = null, array $parameters = []): void
    {
        $this->schedule(launchedBy: $recipient, parameters: $parameters);
    }

    /**
     * Resolve a report instance from a signed token, restricted to `Report`
     * subclasses (tighter than the inherited `Exporter::fromToken()`, which
     * accepts any Exporter).
     */
    public static function fromToken(string $token): self
    {
        $class = Crypt::decryptString($token);

        if (! class_exists($class) || ! is_subclass_of($class, self::class)) {
            throw new RuntimeException('Invalid report token.');
        }

        return new $class;
    }
}
