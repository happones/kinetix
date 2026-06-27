---
name: kinetix-reports
description: "Email an Exporter's output on a schedule. Register reports with KinetixReports::register(ScheduledReport::make(...)); run kinetix:reports:send from the scheduler. Activates when building scheduled/recurring/emailed reports or exports."
license: MIT
metadata:
  author: happones
---

# Kinetix Scheduled Reports

## When to Apply

Activate this skill when:
- Emailing a recurring report / export (daily/weekly/monthly).
- Sending an Exporter's output to recipients on a schedule.

## Documentation

For full details, reference `docs/reports.md` (published at https://happones.github.io/kinetix/reports).

## Define reports

In a service provider `boot()`:

```php
use Happones\Kinetix\Reports\KinetixReports;
use Happones\Kinetix\Reports\ScheduledReport;

KinetixReports::register(
    ScheduledReport::make('daily-orders')
        ->exporter(OrdersExporter::class)   // an existing Kinetix Exporter
        ->frequency('daily')                // daily | weekly | monthly | any label
        ->to(['ops@acme.com'])
        ->subject('Daily orders')
        ->parameters(['status' => 'paid']),
);
```

## Schedule the command

```php
Schedule::command('kinetix:reports:send --frequency=daily')->dailyAt('06:00');
```

Run one on demand: `php artisan kinetix:reports:send daily-orders`.

## How it works

`ReportRunner` builds the Exporter's file (CSV/XLSX/PDF, via the shared
`FileWriter`) and mails it as an attachment (`ScheduledReportMail`, queueable) to
the recipients. Reports without recipients are skipped. Config:
`'reports' => ['enabled' => true]`. i18n `report_mail_intro`/`report_mail_outro`.
