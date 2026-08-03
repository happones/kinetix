---
name: kinetix-reports-center
description: "Queued, DB-tracked CSV/XLSX report generation for large datasets — live progress, cancellation, retry, disk-agnostic downloads, plus one-off/recurring scheduling. Define a Report class under app/Kinetix/Reports (auto-discovered); mount <KinetixReportLauncher>, <KinetixReportRunsTable>, <KinetixReportSchedules>, or the tabbed <KinetixReportsCenter>. Activates when building large-dataset exports, a 'failed jobs'-style run tracker, or scheduled/recurring reports with a download UI."
license: MIT
metadata:
  author: happones
---

# Kinetix Reports Center Development

## When to Apply

Activate this skill when:
- Generating large CSV/XLSX reports that must be chunked, queued, and tracked.
- Building a launcher UI for report "types," a runs table with progress/cancel/
  retry/download, or a scheduled/recurring reports list.
- The request mentions cancelling a running export, resuming/retrying a failed
  one, or downloading a previously generated report file.

Do **not** use this for a simple recurring email export — that's the lighter
[`kinetix-reports`](../kinetix-reports/SKILL.md) skill (`Happones\Kinetix\Reports`,
config `kinetix.reports`): it emails an Exporter's output on a cadence, with no
DB row, no progress, no cancellation, and no download UI. Reports Center
(`Happones\Kinetix\ReportsCenter`, config `kinetix.reports_center`) is the
productized version — every run is tracked, cancellable, retryable, and
downloadable from a UI. The two systems are independent; use whichever fits,
or both.

## Documentation

For full details, reference `docs/reports-center.md` (published at
https://happones.github.io/kinetix/reports-center).

## Installation & Configuration

No migration to publish — `kinetix_report_schedules` and `kinetix_report_runs`
ship with the package's own migrations.

```php
'reports_center' => [
    'enabled' => env('KINETIX_REPORTS_CENTER_ENABLED', false),

    // Directory (+ namespace) auto-scanned for `Report` subclasses.
    'discover_path'      => app_path('Kinetix/Reports'),
    'discover_namespace' => 'App\\Kinetix\\Reports',

    // Frontend poll interval (ms) for the runs/schedules widgets.
    'poll' => env('KINETIX_REPORTS_CENTER_POLL', 5000),

    // Days a completed run's row + file are kept before pruning.
    'retention_days' => env('KINETIX_REPORTS_CENTER_RETENTION_DAYS', 7),
],
```

Reports are stored on the same disk as the rest of Kinetix
(`config('kinetix.filesystem.disk')`) — no separate disk setting. Queue
placement follows the same per-class `->queue()` override `Exporter` already
has — no separate global queue connection setting either.

## Define a report type

```bash
php artisan kinetix:make-report MonthlyInvoicesReport
```

```php
namespace App\Kinetix\Reports;

use Happones\Kinetix\Exports\ExportColumn;
use Happones\Kinetix\ReportsCenter\Report;
use App\Models\Invoice;

class MonthlyInvoicesReport extends Report
{
    protected static ?string $model = Invoice::class;

    public function label(): string { return 'Monthly Invoices'; }
    public function description(): ?string { return 'Every invoice, with line-item totals.'; }
    public function format(): string { return 'xlsx'; }

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id'),
            ExportColumn::make('customer.name')->label('Customer'),
        ];
    }
}
```

`Report extends Exporter` unchanged — `query()`/`getColumns()`/`chunkSize()`
(default 1000)/`format()` all work the same, so large datasets are always
processed in chunks, never loaded into memory at once. Classes placed under
`discover_path` are picked up automatically — no manual registration. To
register one living elsewhere:

```php
use Happones\Kinetix\ReportsCenter\KinetixReportsCenter;

KinetixReportsCenter::register(\App\Reports\SomeOtherReport::class);
```

## Frontend

```vue
<KinetixReportsCenter />
```

...or the three views independently — both are equally valid:

```vue
<KinetixReportLauncher />     <!-- pick a report type, "Run now" -->
<KinetixReportRunsTable />    <!-- status/progress/download/cancel/retry -->
<KinetixReportSchedules />    <!-- recurring/scheduled definitions -->
```

All take zero props — they self-fetch from gated endpoints and poll on
`kinetix.reports_center.poll`.

## Progress, cancellation, and retry

- **Progress** updates once per chunk (not per row) — `processed_rows` always
  increments; the percentage only shows when `estimatesTotal()` (default
  `true`) could count the query upfront.
- **Cancel** marks the run cancelled; the job notices on its *next* chunk
  boundary and stops there. It does not, and cannot, kill the queue worker
  process — this is cooperative cancellation, working identically across
  every queue driver (database, Redis, SQS, Horizon).
- **Retry** (only once `failed` or `cancelled`) dispatches a **fresh** run
  with the same report/parameters — it does not reuse Laravel's own automatic
  job retries, reserved for the job's own transient-failure handling.
- **Download** is only available while `status === 'completed'` and before
  `expires_at` — a real, row-backed expiry.

## Scheduling & recurrence

```php
use Happones\Kinetix\ReportsCenter\ReportSchedule;

ReportSchedule::create([
    'report_class' => \App\Kinetix\Reports\MonthlyInvoicesReport::class,
    'frequency'    => 'monthly', // once | daily | weekly | monthly
    'enabled'      => true,
    'next_run_at'  => now(),
]);
```

```php
Schedule::command('kinetix:report-schedules:dispatch-due')->everyMinute();
```

Each due schedule creates a new `ReportRun`, advances `next_run_at` per its
frequency, and (for `once`) disables itself after firing.

## Pruning old runs

```bash
php artisan kinetix:report-runs:prune {--days=}
```

Deletes the file + row for completed runs past `expires_at`, and the row
alone for failed/cancelled runs older than `retention_days`.

## UUID / ULID Host Models

The published migration types `created_by_id`, `launched_by_id` and `team_id` as `unsignedBigInteger`. If the
referenced model uses UUIDs or ULIDs, publish
`--tag=kinetix-reports-center-migrations` and retype those columns
(`$table->uuid(…)` / `$table->ulid(…)`) BEFORE `php artisan migrate` —
type each column after the model it points to. Full recipe: the
`kinetix-boost` skill, section "UUID / ULID Host Models".
