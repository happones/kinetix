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

## Security (REQUIRED)

- **Each report authorizes itself.** `viewKinetixReportsCenter` only opens the
  module; the report's inherited `Exporter::authorize($user)` (the model's
  `viewAny` policy, `ability()`, or an override) is enforced on launch, retry,
  schedule create and run-now (403, the requester), by
  `kinetix:report-schedules:dispatch-due` (the creator; skipped + logged, the
  schedule still advances), and the `report-types` list hides what the viewer
  can't run.
- **Reserved parameter keys.** `ids` and `relation` are server-only
  (`Report::RESERVED_PARAMETERS`); the launcher and schedule store/update drop
  them via `Report::clientParameters()`. Never pass client `parameters` to a
  run without it.
- **A relation scope is a seal.** `resolveExportQuery()` honours `relation`
  only as a `RelationScope::seal($parent, $name, $exporterClass)` string; a
  plain array, a forged value or another purpose exports zero rows. Never call
  a stored or client-supplied method name (`Model::isRelation()` is no guard:
  it is true for `delete`).
- **Runs follow the report's access.** The runs list only shows report
  classes the viewer may run; download and cancel answer 403 otherwise. Retry
  and run-now also require the user the run is launched as (the launcher /
  the schedule's creator) to pass `authorize()` in the row's team, and the
  scheduler judges the creator inside the schedule's team (spatie team id
  pinned), so team roles count.

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
`kinetix.reports_center.poll` through `useKinetixPolling` (no overlap, paused
while hidden).

`GET {prefix}/report-runs` returns the table plus `hasActive`
(`KinetixReportRunsPayload`): whether any run in the SAME scoped query the list
shows (`forCurrentTeam()` + the classes the viewer may run) is
`ReportRunStatus::active()` (pending/running). While false, the runs list polls
every 60 s. The retry action carries `->dispatch('report-runs-changed')` and the
launcher dispatches `kinetix:report-runs-changed` after a launch (name exported
as `KINETIX_REPORT_RUNS_CHANGED` from `@/composables/useKinetixReportRuns`);
that event puts the list back to full speed. Dispatch it too from any custom UI
that queues runs.

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
frequency, and (for `once`) disables itself after firing. A schedule whose
creator fails the report's `authorize()` is skipped and logged.

## Pruning old runs

```bash
php artisan kinetix:report-runs:prune {--days=}
```

Deletes the file + row for completed runs past `expires_at`, and the row
alone for failed/cancelled runs older than `retention_days`.

## UUID / ULID Host Models

This feature's migration builds `created_by_id`, `launched_by_id` and `team_id` with
`Happones\Kinetix\Support\HostKeys`, which types each column after YOUR model
at migrate time from its primary key (`HasUlids` -> ulid, `HasUuids` -> uuid, string `$keyType` ->
string, else bigint). Pin `kinetix.key_types.user|team` when detection cannot
see the setup; morph ids follow `kinetix.key_types.morph` (default bigint) —
set it when the referenced models use UUIDs/ULIDs. Apps migrated on an older
Kinetix have bigint columns on disk and need their own ALTER migration. Full
recipe: the `kinetix-boost` skill, section "UUID / ULID Host Models".
