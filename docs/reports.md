# Scheduled Reports

Email an [Exporter](/import-export)'s output **on a schedule**. Define reports in
code, run `kinetix:reports:send` from your scheduler, and each due report builds
its export file (CSV / XLSX / PDF) and mails it to the recipients as an
attachment.

---

## 1. Define reports

Register reports in a service provider's `boot()` (they reference an existing
Exporter class):

```php
use Happones\Kinetix\Reports\KinetixReports;
use Happones\Kinetix\Reports\ScheduledReport;
use App\Kinetix\Exporters\OrdersExporter;

KinetixReports::register(
    ScheduledReport::make('daily-orders')
        ->exporter(OrdersExporter::class)
        ->frequency('daily')                 // daily | weekly | monthly | any label
        ->to(['ops@acme.com', 'cfo@acme.com'])
        ->subject('Daily orders report')
        ->parameters(['status' => 'paid']),  // reach these in the Exporter via parameter()
);
```

| Method            | Effect |
| ----------------- | ------ |
| `exporter(class)` | The Exporter whose output is sent (its `format()` decides CSV/XLSX/PDF) |
| `frequency(str)`  | The cadence label this report fires on (default `daily`) |
| `to(array\|string)` | Recipient email(s) |
| `subject(str)`    | Email subject (defaults to the key's headline) |
| `parameters([])`  | Runtime parameters passed to the Exporter |
| `enabled(bool)`   | Toggle the report off without removing it |

---

## 2. Schedule the command

`kinetix:reports:send` runs the due reports. Filter by `--frequency` and wire one
line per cadence in your scheduler:

```php
// routes/console.php (or bootstrap/app.php ->withSchedule)
use Illuminate\Support\Facades\Schedule;

Schedule::command('kinetix:reports:send --frequency=daily')->dailyAt('06:00');
Schedule::command('kinetix:reports:send --frequency=weekly')->mondays()->at('06:00');
Schedule::command('kinetix:reports:send --frequency=monthly')->monthlyOn(1, '06:00');
```

Run one on demand (any frequency, ignores the enabled filter target by key):

```bash
php artisan kinetix:reports:send daily-orders
```

---

## How it works

For each due report the runner instantiates the Exporter (with the report's
parameters), streams its rows to a temp file via the same `FileWriter` used by
on-demand exports (headings + chunked records + an optional summary row), then
sends a `ScheduledReportMail` with the file attached to every recipient and
cleans up the temp file. Reports with no recipients are skipped.

Enable the feature in config:

```php
'reports' => ['enabled' => env('KINETIX_REPORTS_ENABLED', true)],
```

::: tip Queue it
The mailable uses `Queueable`, so configuring a queue connection means the
generated reports are sent off the scheduler tick.
:::
